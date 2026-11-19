<?php

use App\Domains\Migration\Jobs\RunMigrationJob;
use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Models\QuarantineEntry;
use App\Domains\Migration\Support\MigrationJobs;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\LegacyFixture as Legacy;

uses(RefreshDatabase::class);

/*
| WP11 extras — Admin → Data migration: runs, quarantine review (masked row,
| logged reveal, resolve / waive with the actor), single-row and step re-tries
| on the existing machinery (dry run first, auto-resolve), legacy records,
| redirects and reconciliation. Against the small legacy fixture snapshot.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    Storage::fake('local');
    config(['nawy.legacy.reconcile_report' => storage_path('framework/testing/admin-reconciliation-report.md')]);
    Legacy::reset();
    $hash = password_hash('secret-password', PASSWORD_BCRYPT);
    $this->stores = ['ok' => Legacy::uuid(), 'nowhere' => Legacy::uuid(), 'owner' => Legacy::uuid()];
    Legacy::insert('vendors', [
        ['id' => $this->stores['ok'], 'name' => 'Store Owner', 'email' => 'store@legacy.test', 'password' => $hash, 'store_name' => 'Legacy Store', 'store_slug' => 'legacy-store', 'country_id' => Legacy::COUNTRIES['uae'], 'global_status' => 'active', 'vendor_type' => 'product_vendor', 'payout_schedule' => 'monthly'],
        ['id' => $this->stores['nowhere'], 'name' => 'Nowhere Owner', 'email' => 'nowhere@legacy.test', 'password' => $hash, 'store_name' => 'Nowhere', 'store_slug' => 'nowhere', 'country_id' => null, 'global_status' => 'active'],
    ]);
    Legacy::insert('vendor_admins', [['id' => $this->stores['owner'], 'vendor_id' => $this->stores['ok'], 'name' => 'Store Owner', 'email' => 'store@legacy.test', 'password' => $hash, 'role' => 'vendor_owner', 'is_owner' => 1, 'is_active' => 1]]);

    $this->artisan('legacy:migrate', ['step' => 'identity'])->assertSuccessful();
    $this->entry = QuarantineEntry::query()->where('source_table', 'vendors')->where('source_id', $this->stores['nowhere'])->sole();
});

test('super admins and Ops reach every Data migration page; other roles do not', function () {
    $admin = adminAccount();
    $ops = adminAccount('uae', 'operations');
    $finance = adminAccount('uae', 'finance');

    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine'))->assertOk()
        ->assertSee('Data migration')->assertSee('Quarantine queue')->assertSee('Legacy redirects')->assertSee('Reconciliation');

    foreach (['migration/quarantine', "migration/quarantine/{$this->entry->id}", 'migration/runs', 'migration/records', 'migration/redirects', 'migration/reconciliation'] as $page) {
        $this->actingAs($ops)->get(onPanel('admin', $page))->assertOk();
        $this->actingAs($finance)->get(onPanel('admin', $page))->assertForbidden();
    }

    $this->actingAs($finance)->get(onPanel('admin', 'dashboard'))->assertDontSee('Quarantine queue');
    $this->actingAs($finance)->post(onPanel('admin', "migration/quarantine/{$this->entry->id}/review"), ['decision' => 'waived', 'note' => 'x'])->assertForbidden();
});

test('the queue filters by step, table, country, reason and status', function () {
    $admin = adminAccount();
    $row = "data-quarantine=\"{$this->entry->id}\"";

    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine'))->assertOk()->assertSee($row, false)->assertSee('country_unresolved');
    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine?step=identity&table=vendors&reason=country_unresolved'))->assertSee($row, false);
    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine?table=orders'))->assertDontSee($row, false);
    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine?country=kwt'))->assertDontSee($row, false);
    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine?status=waived'))->assertDontSee($row, false);
    $this->actingAs($admin)->get(onPanel('admin', 'migration/quarantine?q='.substr($this->stores['nowhere'], 0, 8)))->assertSee($row, false);
});

test('the legacy row is masked until revealed, and every reveal is logged with the admin', function () {
    $ops = adminAccount('uae', 'operations');
    $entry = $this->entry;
    $entry->forceFill(['payload' => ['id' => $this->stores['nowhere'], 'email' => 'nowhere@legacy.test', 'phone' => '+971500000009', 'password' => '$2y$hash', 'store_name' => 'Nowhere', 'country_id' => null]])->save();

    $this->actingAs($ops)->get(onPanel('admin', "migration/quarantine/{$entry->id}"))->assertOk()
        ->assertDontSee('nowhere@legacy.test')->assertDontSee('+971500000009')->assertDontSee('$2y$hash')
        ->assertSee($this->stores['nowhere'])->assertSee('Reveal personal data');

    $this->actingAs($ops)->post(onPanel('admin', "migration/quarantine/{$entry->id}/reveal"))->assertOk()
        ->assertSee('nowhere@legacy.test')->assertSee('+971500000009')->assertDontSee('$2y$hash')->assertSee('[hidden]');

    $log = Activity::query()->where('log_name', 'data_migration')->where('event', 'pii_revealed')->sole();
    expect($log->causer_id)->toBe($ops->id)->and($log->subject_id)->toBe($entry->id);
});

test('resolve and waive need a note and are audit-logged with the actor; a waived row stays quarantined for reconciliation', function () {
    $ops = adminAccount('uae', 'operations');
    $url = onPanel('admin', "migration/quarantine/{$this->entry->id}/review");

    $this->actingAs($ops)->post($url, ['decision' => 'waived'])->assertSessionHasErrors('note');
    $this->actingAs($ops)->post($url, ['decision' => 'waived', 'note' => 'Test store, never traded.'])->assertRedirect()->assertSessionHasNoErrors();

    $entry = $this->entry->refresh();
    expect($entry->status())->toBe('waived')
        ->and($entry->reviewed_by)->toBe($ops->id)
        ->and($entry->review_note)->toBe('Test store, never traded.')
        ->and($entry->resolved_at)->toBeNull()
        ->and(DB::table('migration_quarantine')->whereNull('resolved_at')->where('source_id', $this->stores['nowhere'])->exists())->toBeTrue();

    $log = Activity::query()->where('event', 'quarantine_waived')->sole();
    expect($log->causer_id)->toBe($ops->id)->and($log->properties['note'])->toBe('Test store, never traded.');

    $this->actingAs($ops)->post($url, ['decision' => 'resolved', 'note' => 'Country set in the old admin.'])->assertRedirect();
    expect($this->entry->refresh()->status())->toBe('resolved');
    $this->actingAs($ops)->get(onPanel('admin', 'migration/quarantine?status=resolved'))->assertSee("data-quarantine=\"{$entry->id}\"", false);
    $this->actingAs($ops)->get(onPanel('admin', "migration/quarantine/{$entry->id}"))->assertSee('Country set in the old admin.')->assertSee('Quarantine entry marked resolved');

    // Still failing on the next run: back to the open queue (a waiver would stand).
    $this->artisan('legacy:migrate', ['step' => 'identity'])->assertSuccessful();
    expect($this->entry->refresh()->status())->toBe('open');
});

test('a single row is re-tried with a dry run first, then applied, and leaves the quarantine when it migrates', function () {
    $ops = adminAccount('uae', 'operations');
    $mapped = fn (): bool => DB::table('migration_id_map')->where('source_table', 'vendors')->where('source_id', $this->stores['nowhere'])->exists();

    // Still broken at the source: the preview says so and writes nothing.
    $this->actingAs($ops)->post(onPanel('admin', "migration/quarantine/{$this->entry->id}/preview"))->assertRedirect();
    $preview = MigrationJob::query()->latest('id')->sole();
    expect($preview->dry_run)->toBeTrue()->and($preview->status)->toBe('done')
        ->and($preview->metrics['tables']['vendors']['read'])->toBe(1)
        ->and($preview->metrics['tables']['vendors']['quarantined'])->toBe(1)
        ->and($preview->metrics['tables'])->not->toHaveKey('vendor_admins')
        ->and($mapped())->toBeFalse();

    Legacy::update('vendors', $this->stores['nowhere'], ['country_id' => Legacy::COUNTRIES['kwt']]);
    $this->actingAs($ops)->post(onPanel('admin', "migration/quarantine/{$this->entry->id}/preview"))->assertRedirect();
    $preview = MigrationJob::query()->latest('id')->first();
    expect($preview->total('written'))->toBeGreaterThan(0)->and($mapped())->toBeFalse()
        ->and($this->entry->refresh()->resolved_at)->toBeNull();
    $this->actingAs($ops)->get(onPanel('admin', "migration/quarantine/{$this->entry->id}"))->assertSee('Apply re-try');

    $this->actingAs($ops)->post(onPanel('admin', "migration/jobs/{$preview->id}/apply"))->assertRedirect(onPanel('admin', "migration/quarantine/{$this->entry->id}"));
    expect($mapped())->toBeTrue()
        ->and($this->entry->refresh()->resolved_at)->not->toBeNull()
        ->and($this->entry->status())->toBe('auto_resolved')
        ->and(DB::table('migration_runs')->latest('id')->value('scope'))->toBe("row:vendors:{$this->stores['nowhere']}")
        ->and(Activity::query()->where('event', 'retry_applied')->sole()->causer_id)->toBe($ops->id);

    // A preview is applied once.
    $this->actingAs($ops)->post(onPanel('admin', "migration/jobs/{$preview->id}/apply"))->assertSessionHasErrors('action');
    expect(MigrationJob::query()->where('dry_run', false)->count())->toBe(1);
});

test('a step re-try for one country is queued as a dry run, then applied, and resolves what it migrates in that country', function () {
    Queue::fake();
    $ops = adminAccount('uae', 'operations');
    Legacy::update('vendors', $this->stores['nowhere'], ['country_id' => Legacy::COUNTRIES['kwt']]);

    $this->actingAs($ops)->post(onPanel('admin', 'migration/retries'), ['step' => 'nope'])->assertSessionHasErrors('step');
    $this->actingAs($ops)->post(onPanel('admin', 'migration/retries'), ['step' => 'identity', 'country' => 'kwt'])->assertRedirect();
    $preview = MigrationJob::query()->sole();
    Queue::assertPushedOn('migration', RunMigrationJob::class, fn (RunMigrationJob $job): bool => $job->migrationJobId === $preview->id);
    $this->actingAs($ops)->get(onPanel('admin', "migration/jobs/{$preview->id}"))->assertOk()->assertSee('Queued')->assertSee('http-equiv="refresh"', false);

    (new RunMigrationJob($preview->id))->handle(app(MigrationJobs::class));
    $preview->refresh();
    expect($preview->status)->toBe('done')->and($this->entry->refresh()->resolved_at)->toBeNull();
    $this->actingAs($ops)->get(onPanel('admin', "migration/jobs/{$preview->id}"))->assertOk()->assertSee('Dry run result')->assertSee('data-metrics-table="vendors"', false);

    $this->actingAs($ops)->post(onPanel('admin', "migration/jobs/{$preview->id}/apply"))->assertRedirect();
    $run = MigrationJob::query()->where('preview_id', $preview->id)->sole();
    expect($run->dry_run)->toBeFalse()->and($run->country_code)->toBe('kwt');
    (new RunMigrationJob($run->id))->handle(app(MigrationJobs::class));

    expect($run->refresh()->status)->toBe('done')
        ->and($this->entry->refresh()->resolved_at)->not->toBeNull()
        ->and(DB::table('migration_runs')->latest('id')->value('countries'))->toBe('kwt');
});

test('runs list and detail show counts and the steps of the run', function () {
    $admin = adminAccount();
    $run = DB::table('migration_runs')->where('step', 'identity')->latest('id')->first();

    $this->actingAs($admin)->get(onPanel('admin', 'migration/runs'))->assertOk()->assertSee("data-run=\"{$run->id}\"", false);
    $this->actingAs($admin)->get(onPanel('admin', 'migration/runs?step=orders'))->assertDontSee("data-run=\"{$run->id}\"", false);
    $this->actingAs($admin)->get(onPanel('admin', "migration/runs/{$run->id}"))->assertOk()->assertSee('data-metrics-table="vendors"', false)->assertSee($run->run_id);
});

test('legacy records are browsed read only by database, table and id, masked until revealed', function () {
    $admin = adminAccount();
    $id = DB::connection('country_uae')->table('legacy_records')->insertGetId([
        'source_table' => 'support_tickets', 'source_id' => 'legacy-ticket-1', 'rule' => 'R3', 'reason' => 'archive',
        'payload' => json_encode(['id' => 'legacy-ticket-1', 'requester_email' => 'buyer@legacy.test', 'subject' => 'Late parcel']), 'checksum' => sha1('x'), 'archived_at' => now(),
    ]);

    $this->actingAs($admin)->get(onPanel('admin', 'migration/records?database=uae&table=support_tickets&q=ticket-1'))->assertOk()->assertSee('legacy-ticket-1');
    $this->actingAs($admin)->get(onPanel('admin', 'migration/records?database=central'))->assertDontSee('legacy-ticket-1');
    $this->actingAs($admin)->get(onPanel('admin', "migration/records/uae/{$id}"))->assertOk()->assertSee('Late parcel')->assertDontSee('buyer@legacy.test');
    $this->actingAs($admin)->post(onPanel('admin', "migration/records/uae/{$id}/reveal"))->assertOk()->assertSee('buyer@legacy.test');
    $this->actingAs($admin)->get(onPanel('admin', "migration/records/xyz/{$id}"))->assertNotFound();
    expect(Activity::query()->where('event', 'pii_revealed')->sole()->properties['legacy_record'])->toBe($id);
});

test('redirects are searchable, exported from the legacy:redirects files and rebuilt on the queue', function () {
    Queue::fake();
    $admin = adminAccount();
    $this->artisan('legacy:redirects')->assertSuccessful();

    $this->actingAs($admin)->get(onPanel('admin', 'migration/redirects?q=my-bookings&source=storefront&country=uae'))->assertOk()
        ->assertSee('/uae-ar/my-bookings')->assertDontSee('/omn-en/my-bookings')->assertSee('Export nginx map');

    $json = $this->actingAs($admin)->get(onPanel('admin', 'migration/redirects/export.json'))->assertOk()->assertDownload('legacy-redirects.json');
    expect(json_decode($json->streamedContent(), true)['/uae-en/my-bookings'])->toBe('/uae-en/account/bookings');
    expect($this->actingAs($admin)->get(onPanel('admin', 'migration/redirects/export.map'))->assertOk()->streamedContent())
        ->toContain('"/uae-en/my-bookings" "/uae-en/account/bookings";');

    $this->actingAs($admin)->post(onPanel('admin', 'migration/redirects/rebuild'))->assertRedirect();
    Queue::assertPushedOn('migration', RunMigrationJob::class);
    $job = MigrationJob::query()->where('kind', 'redirects')->sole();
    (new RunMigrationJob($job->id))->handle(app(MigrationJobs::class));
    expect($job->refresh()->status)->toBe('done')->and($job->output)->toContain('redirect(s)');
});

test('reconcile is queued from the panel and its report is rendered with the summary', function () {
    Queue::fake();
    $admin = adminAccount();
    File::delete(config('nawy.legacy.reconcile_report'));

    $this->actingAs($admin)->get(onPanel('admin', 'migration/reconciliation'))->assertOk()->assertSee('No report yet');
    $this->actingAs($admin)->post(onPanel('admin', 'migration/reconciliation'))->assertRedirect();
    $this->actingAs($admin)->post(onPanel('admin', 'migration/reconciliation'))->assertSessionHasErrors('action');
    Queue::assertPushedOn('migration', RunMigrationJob::class);

    $job = MigrationJob::query()->where('kind', 'reconcile')->sole();
    (new RunMigrationJob($job->id))->handle(app(MigrationJobs::class));
    expect($job->refresh()->status)->toBe('done')->and($job->metrics)->toHaveKey('exit_code')
        ->and(File::exists(config('nawy.legacy.reconcile_report')))->toBeTrue();

    $this->actingAs($admin)->get(onPanel('admin', 'migration/reconciliation'))->assertOk()
        ->assertSee('<table>', false)->assertSee('Legacy migration — reconciliation report')->assertSee('Migrated or archived')
        ->assertDontSee('nowhere@legacy.test');
});

test('legacy:migrate --row re-runs one row from the command line too', function () {
    Legacy::update('vendors', $this->stores['nowhere'], ['country_id' => Legacy::COUNTRIES['kwt']]);

    $this->artisan('legacy:migrate', ['step' => 'all', '--row' => 'vendors:'.$this->stores['nowhere']])->assertFailed();
    $this->artisan('legacy:migrate', ['step' => 'identity', '--row' => 'vendors:'.$this->stores['nowhere']])->assertSuccessful();

    expect($this->entry->refresh()->resolved_at)->not->toBeNull()
        ->and(DB::table('migration_quarantine')->whereNull('resolved_at')->count())->toBe(0);
});
