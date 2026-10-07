{{-- Certificate email. Inline styles only: most email clients strip <link> and <style>. --}}
@php
    $post = $data['post'];
    $confidence = is_numeric($post->confidence) ? number_format($post->confidence, 1) . '%' : '—';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MyCow breed certificate</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f7f4ec;font-family:Arial,Helvetica,sans-serif;color:#1c251e;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e4dfd2;border-radius:14px;overflow:hidden;">
    <tr>
      <td style="background:#1b5e37;padding:22px 28px;color:#ffffff;">
        <div style="font-size:20px;font-weight:bold;">MyCow</div>
        <div style="font-size:13px;color:#e0a526;letter-spacing:1px;text-transform:uppercase;margin-top:4px;">Breed certificate</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px;border-top:4px solid #e0a526;">
        <p style="margin:0 0 6px;font-size:15px;color:#4a554c;">This certifies that the animal submitted by</p>
        <p style="margin:0 0 20px;font-size:22px;font-weight:bold;">{{ $post->user->fullname ?? 'MyCow member' }}</p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4dfd2;border-radius:10px;">
          <tr>
            <td style="padding:12px 16px;color:#6b746c;font-size:14px;">Cow</td>
            <td style="padding:12px 16px;font-weight:bold;text-align:right;">{{ $post->cow_name }}</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#6b746c;font-size:14px;border-top:1px solid #e4dfd2;">Breed</td>
            <td style="padding:12px 16px;font-weight:bold;text-align:right;border-top:1px solid #e4dfd2;color:#1b5e37;">{{ str_replace('-', ' ', $post->predicted_class) }}</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#6b746c;font-size:14px;border-top:1px solid #e4dfd2;">Model confidence</td>
            <td style="padding:12px 16px;font-weight:bold;text-align:right;border-top:1px solid #e4dfd2;">{{ $confidence }}</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#6b746c;font-size:14px;border-top:1px solid #e4dfd2;">Certified on</td>
            <td style="padding:12px 16px;font-weight:bold;text-align:right;border-top:1px solid #e4dfd2;">{{ now()->format('j F Y') }}</td>
          </tr>
        </table>

        <p style="margin:20px 0 0;font-size:14px;color:#4a554c;">
          The breed was identified by the MyCow model and confirmed by a livestock officer.
          Keep this email as your record.
        </p>
        <p style="margin:14px 0 0;font-size:12px;color:#6b746c;">Reference: {{ $post->id }}</p>
      </td>
    </tr>
  </table>
</body>
</html>
