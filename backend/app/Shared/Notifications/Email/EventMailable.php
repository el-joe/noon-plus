<?php

namespace App\Shared\Notifications\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The message of one catalogue email: rendered HTML + plain text,
 * attachments, the email log id and event as headers / provider metadata
 * (so bounce webhooks find the log row), and RFC 8058 one-click
 * unsubscribe headers for mail that can be turned off.
 */
class EventMailable extends Mailable
{
    use Queueable;

    /**
     * @param  array<int, array{data: string, name: string, mime: string}>  $files
     */
    public function __construct(
        public RenderedEmail $rendered,
        public string $recipientEmail,
        public ?string $recipientName,
        public string $event,
        public ?int $logId = null,
        public array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->rendered->subject,
            tags: [$this->event],
            metadata: array_filter(['email_log_id' => $this->logId === null ? null : (string) $this->logId, 'event' => $this->event]),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.raw-text', with: ['plain' => $this->rendered->text], htmlString: $this->rendered->html);
    }

    public function headers(): Headers
    {
        $text = array_filter([
            'X-Nawy-Event' => $this->event,
            'X-Nawy-Email-Log' => $this->logId === null ? null : (string) $this->logId,
        ]);

        if ($this->rendered->oneClickUrl !== null) {
            $text['List-Unsubscribe'] = '<'.$this->rendered->oneClickUrl.'>'.($this->rendered->unsubscribeUrl === null ? '' : ', <'.$this->rendered->unsubscribeUrl.'>');
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $text);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(fn (array $file): Attachment => Attachment::fromData(fn (): string => $file['data'], $file['name'])->withMime($file['mime']), $this->files);
    }
}
