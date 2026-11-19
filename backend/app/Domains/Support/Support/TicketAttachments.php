<?php

namespace App\Domains\Support\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Models\SupportTicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ticket attachments (QC-007, like return photos QC-049): stored on the
 * private disk under a random name with the extension detected from the
 * content (never the client's), and opened only through expiring signed
 * links handed out after the caller's own authorisation.
 */
class TicketAttachments
{
    public const DISK = 'local';

    public const LINK_MINUTES = 30;

    public function __construct(private CountryContext $countryContext) {}

    /**
     * Validation rules for one `attachments.*` file.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['file', 'mimes:'.implode(',', config('nawy.support.attachment_mimes')), 'max:'.(int) config('nawy.support.attachment_max_kb')];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, name: string, mime: string, size: int}>
     */
    public function store(SupportTicket $ticket, array $files): array
    {
        return array_values(array_map(fn (UploadedFile $file): array => [
            'path' => $file->store('support/'.$this->countryContext->current()->code.'/'.$ticket->number, self::DISK),
            'name' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 80).'.'.($file->extension() ?: 'bin'),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ], $files));
    }

    /**
     * @return array<int, array{name: string, mime: string, size: int, url: string}>
     */
    public function present(SupportTicketMessage $message): array
    {
        $country = $this->countryContext->current();
        $market = $country->marketSlug(in_array(app()->getLocale(), $country->locales, true) ? app()->getLocale() : $country->default_locale);

        return array_map(fn (array $file, int $index): array => [
            'name' => $file['name'],
            'mime' => $file['mime'],
            'size' => $file['size'],
            'url' => URL::temporarySignedRoute('api.v1.support.attachment', now()->addMinutes(self::LINK_MINUTES), [
                'market' => $market,
                'message' => $message->id,
                'index' => $index,
            ]),
        ], array_values($message->attachments ?? []), array_keys(array_values($message->attachments ?? [])));
    }

    public function stream(SupportTicketMessage $message, int $index): StreamedResponse
    {
        $file = array_values($message->attachments ?? [])[$index] ?? abort(404);

        abort_unless(Storage::disk(self::DISK)->exists($file['path']), 404);

        return Storage::disk(self::DISK)->response($file['path'], $file['name'], ['Cache-Control' => 'private, max-age=600']);
    }
}
