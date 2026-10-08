{{-- Workflow email (see App\Mail\NoticeMail). Inline styles only: most email clients strip <link> and <style>. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f7f4ec;font-family:Arial,Helvetica,sans-serif;color:#1c251e;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e4dfd2;border-radius:14px;overflow:hidden;">
    <tr>
      <td style="background:#1b5e37;padding:20px 28px;color:#ffffff;">
        <div style="font-size:20px;font-weight:bold;">MyCow</div>
        <div style="font-size:12px;color:#e0a526;letter-spacing:1px;text-transform:uppercase;margin-top:4px;">Cattle certification · Kenya</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px;border-top:4px solid #e0a526;">
        <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;">{{ $heading }}</h1>

        @foreach ($lines as $line)
          <p style="margin:0 0 12px;font-size:15px;line-height:1.55;color:#33403a;">{{ $line }}</p>
        @endforeach

        @php($facts = array_filter($facts, fn ($v) => $v !== null && $v !== ''))
        @if ($facts)
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4dfd2;border-radius:10px;margin:8px 0 4px;">
            @foreach ($facts as $label => $value)
              <tr>
                <td style="padding:10px 14px;color:#6b746c;font-size:14px;{{ $loop->first ? '' : 'border-top:1px solid #e4dfd2;' }}">{{ $label }}</td>
                <td style="padding:10px 14px;font-weight:bold;text-align:right;font-size:14px;{{ $loop->first ? '' : 'border-top:1px solid #e4dfd2;' }}">{{ $value }}</td>
              </tr>
            @endforeach
          </table>
        @endif

        @if ($action)
          <p style="margin:22px 0 4px;">
            <a href="{{ $action['url'] }}" style="display:inline-block;background:#1b5e37;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px;font-size:15px;">{{ $action['label'] }}</a>
          </p>
          <p style="margin:8px 0 0;font-size:12px;color:#6b746c;word-break:break-all;">Or open: {{ $action['url'] }}</p>
        @endif
      </td>
    </tr>
    <tr>
      <td style="padding:14px 28px;background:#faf8f2;font-size:12px;color:#6b746c;">
        You’re getting this because of activity on your MyCow account. Please don’t reply to this email.
      </td>
    </tr>
  </table>
</body>
</html>
