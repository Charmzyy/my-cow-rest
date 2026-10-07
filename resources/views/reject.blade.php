{{-- Sent by RejectedMail. Inline styles only: most email clients strip <link> and <style>. --}}
@php($post = $data['post'])
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your MyCow submission needs another look</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f7f4ec;font-family:Arial,Helvetica,sans-serif;color:#1c251e;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e4dfd2;border-radius:14px;overflow:hidden;">
    <tr>
      <td style="background:#1b5e37;padding:22px 28px;color:#ffffff;">
        <div style="font-size:20px;font-weight:bold;">MyCow</div>
        <div style="font-size:13px;color:#e0a526;letter-spacing:1px;text-transform:uppercase;margin-top:4px;">Submission update</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px;">
        <p style="margin:0 0 14px;font-size:16px;">Hello {{ $post->user->fullname ?? 'there' }},</p>
        <p style="margin:0 0 18px;font-size:15px;color:#4a554c;">
          A livestock officer reviewed your photo of <strong style="color:#1c251e;">{{ $post->cow_name }}</strong>
          and couldn’t certify it this time.
        </p>

        @if($post->reason)
        <div style="background:#f8eaea;border:1px solid #efd0d2;border-radius:10px;padding:14px 16px;margin:0 0 18px;">
          <div style="font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#8a2f35;margin-bottom:6px;">Officer’s note</div>
          <div style="font-size:15px;">{{ $post->reason }}</div>
        </div>
        @endif

        <p style="margin:0 0 6px;font-size:15px;font-weight:bold;">To try again</p>
        <ul style="margin:0 0 18px;padding-left:20px;font-size:14px;color:#4a554c;line-height:1.6;">
          <li>Photograph the whole animal, side-on</li>
          <li>Use good daylight and keep the phone steady</li>
          <li>Only one cow in the photo</li>
        </ul>
        <p style="margin:0;font-size:12px;color:#6b746c;">Reference: {{ $post->id }}</p>
      </td>
    </tr>
  </table>
</body>
</html>
