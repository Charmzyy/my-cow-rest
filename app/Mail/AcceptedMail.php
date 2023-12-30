<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AcceptedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;
    public $image;

    /**
     * Create a new message instance.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->image = $data['image'];
    }

    public function build()
    {
        return $this->subject('Cow Accept')
                    ->view('accepted') // view file for the email
                    ->with([
                        'data' => $this->data ,
                        'image' => $this->image
                    ]);
    }
}
