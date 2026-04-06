<!DOCTYPE html>
<html lang="{{ $language ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $translations['title'] ?? 'Unsubscribe' }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="min-height: 100vh; background-color: #f4f5f7;">
        <tr>
            <td style="padding: 60px 20px;" align="center" valign="middle">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="480" style="max-width: 480px; width: 100%;">
                    <!-- Card -->
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 12px; padding: 48px 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: center;">

                            <!-- Icon -->
                            <div style="margin-bottom: 24px;">
                                <span style="display: inline-block; width: 56px; height: 56px; line-height: 56px; border-radius: 50%; background-color: #fef3c7; font-size: 28px;">&#9993;</span>
                            </div>

                            <h1 style="margin: 0 0 12px; font-size: 24px; font-weight: 700; color: #1a1a2e; line-height: 1.3;">
                                {{ $translations['heading'] ?? 'Unsubscribe from emails' }}
                            </h1>

                            <p style="margin: 0 0 32px; font-size: 15px; line-height: 1.6; color: #6b7280;">
                                {{ $translations['confirm_message'] ?? 'Are you sure you want to unsubscribe? You will no longer receive emails from us.' }}
                            </p>

                            <form method="POST" action="{{ $actionUrl ?? '' }}">
                                @csrf

                                <!-- Reason textarea -->
                                <div style="margin-bottom: 24px; text-align: left;">
                                    <label for="reason" style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #374151;">
                                        {{ $translations['reason_label'] ?? 'Reason (optional)' }}
                                    </label>
                                    <textarea
                                        id="reason"
                                        name="reason"
                                        rows="3"
                                        placeholder="{{ $translations['reason_placeholder'] ?? 'Tell us why you are unsubscribing...' }}"
                                        style="width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; line-height: 1.5; color: #374151; font-family: inherit; resize: vertical; box-sizing: border-box; outline: none; transition: border-color 0.15s;"
                                        onfocus="this.style.borderColor='#6366f1'"
                                        onblur="this.style.borderColor='#d1d5db'"
                                    ></textarea>
                                </div>

                                <button type="submit" style="display: inline-block; padding: 12px 32px; background-color: #dc2626; color: #ffffff; font-size: 15px; font-weight: 600; font-family: inherit; border: none; border-radius: 8px; cursor: pointer; line-height: 1.4; transition: background-color 0.15s;">
                                    {{ $translations['submit_button'] ?? 'Yes, unsubscribe me' }}
                                </button>
                            </form>

                        </td>
                    </tr>

                    <!-- Footer text -->
                    <tr>
                        <td style="padding: 24px 20px 0; text-align: center;">
                            <p style="margin: 0; font-size: 13px; color: #9ca3af; line-height: 1.5;">
                                {{ $translations['footer_note'] ?? 'You can resubscribe at any time from your account settings.' }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
