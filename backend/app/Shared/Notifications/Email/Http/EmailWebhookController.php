<?php

namespace App\Shared\Notifications\Email\Http;

use App\Http\Controllers\Controller;
use App\Shared\Notifications\Email\Webhooks\EmailFeedbackHandler;
use App\Shared\Notifications\Email\Webhooks\EmailWebhookDriver;
use App\Shared\Notifications\Email\Webhooks\GenericDriver;
use App\Shared\Notifications\Email\Webhooks\MailgunDriver;
use App\Shared\Notifications\Email\Webhooks\PostmarkDriver;
use App\Shared\Notifications\Email\Webhooks\SesDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/webhooks/email/{driver}: bounce / complaint / delivery
 * events from the mail provider. Only drivers listed in
 * NAWY_MAIL_WEBHOOK_DRIVERS answer; each verifies its own signature.
 */
class EmailWebhookController extends Controller
{
    /**
     * @var array<string, class-string<EmailWebhookDriver>>
     */
    public const DRIVERS = ['generic' => GenericDriver::class, 'ses' => SesDriver::class, 'postmark' => PostmarkDriver::class, 'mailgun' => MailgunDriver::class];

    public function __invoke(Request $request, string $driver, EmailFeedbackHandler $handler): JsonResponse
    {
        abort_unless(isset(self::DRIVERS[$driver]) && in_array($driver, (array) config('nawy.mail.webhooks.enabled'), true), 404);

        $implementation = app(self::DRIVERS[$driver]);

        abort_unless($implementation->verify($request), 401, 'Invalid signature.');

        $events = $implementation->parse($request);

        foreach ($events as $feedback) {
            $handler->handle($feedback, $driver);
        }

        return response()->json(['received' => count($events)]);
    }
}
