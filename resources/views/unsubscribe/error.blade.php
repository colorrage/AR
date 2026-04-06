<!DOCTYPE html>
<html lang="{{ $language ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $translations['title'] ?? 'Error' }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="min-height: 100vh; background-color: #f4f5f7;">
        <tr>
            <td style="padding: 60px 20px;" align="center" valign="middle">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="480" style="max-width: 480px; width: 100%;">
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 12px; padding: 48px 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: center;">

                            <!-- Error icon -->
                            <div style="margin-bottom: 24px;">
                                <span style="display: inline-block; width: 56px; height: 56px; line-height: 56px; border-radius: 50%; background-color: #fee2e2; font-size: 28px;">&#10007;</span>
                            </div>

                            <h1 style="margin: 0 0 12px; font-size: 24px; font-weight: 700; color: #1a1a2e; line-height: 1.3;">
                                {{ $translations['heading'] ?? 'Something went wrong' }}
                            </h1>

                            <p style="margin: 0; font-size: 15px; line-height: 1.6; color: #6b7280;">
                                {{ $translations['message'] ?? 'We were unable to process your request. Please try again later or contact support if the problem persists.' }}
                            </p>

                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 20px 0; text-align: center;">
                            <p style="margin: 0; font-size: 13px; color: #9ca3af; line-height: 1.5;">
                                {{ $translations['footer_note'] ?? 'If this issue continues, please reach out to our support team.' }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
