<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// One layout for every workflow email: heading, a few lines, a facts table, one button,
// and optionally an attached PDF. Notifier decides the wording.
class NoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string[] $lines   paragraphs
     * @param array<string,string|null> $facts  label => value (null values are skipped)
     * @param array{label:string,url:string}|null $action  the one button
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $lines,
        public array $facts = [],
        public ?array $action = null,
        private ?string $pdfData = null,
        private ?string $pdfName = null,
    ) {
    }

    public function build()
    {
        $mail = $this->subject($this->subjectLine)->view('emails.notice');
        if ($this->pdfData !== null) {
            $mail->attachData($this->pdfData, $this->pdfName, ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
