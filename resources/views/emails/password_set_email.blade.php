<!DOCTYPE html>

<html lang="en" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml">

<head>
    <title></title>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch><o:AllowPNG/></o:OfficeDocumentSettings></xml><![endif]-->
    <style>
        * {
            box-sizing: border-box;
        }

        th.column {
            padding: 0
        }

        a[x-apple-data-detectors] {
            color: inherit !important;
            text-decoration: inherit !important;
        }

        #MessageViewBody a {
            color: inherit;
            text-decoration: none;
        }

        p {
            line-height: inherit
        }

        @media (max-width:620px) {
            .d-none {
                display: none !important;
            }

            .icons-inner {
                text-align: center;
            }

            .icons-inner td {
                margin: 0 auto;
            }

            .row-content {
                width: 100% !important;
            }

            .stack .column {
                width: 100%;
                display: block;
            }
        }
    </style>
</head>

<body style="background-color: #0a0a0a; margin: 0; padding: 0; -webkit-text-size-adjust: none; text-size-adjust: none;">
    <table border="0" cellpadding="0" cellspacing="0" class="nl-container" role="presentation"
        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-color: #0a0a0a;" width="100%">
        <tbody>
            <tr>
                <td>
                    <table align="center" border="0" cellpadding="0" cellspacing="0" class="row row-1"
                        role="presentation"
                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-position: top center;"
                        width="100%">
                        <tbody>
                            <tr>
                                <td>
                                    <table align="center" border="0" cellpadding="0" cellspacing="0"
                                        class="row-content stack" role="presentation"
                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-color: #171717;"
                                        width="600">
                                        <tbody>
                                            <tr>
                                                <th class="column"
                                                    style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; background-color: #171717; padding-left: 40px; padding-right: 40px;"
                                                    width="100%">
                                                    <table border="0" cellpadding="0" cellspacing="0"
                                                        class="image_block" role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;"
                                                        width="100%">
                                                        <tr>
                                                            <td
                                                                style="padding-bottom:20px;padding-top:25px;width:100%;padding-right:0px;padding-left:0px;">
                                                                <div style="line-height:10px"><img
                                                                        src="<?php echo $message->embed(resource_path('views/emails/asset/ticketoc.png')); ?>"
                                                                        style="display: block; height: auto; border: 0; width: 164px; max-width: 100%;"
                                                                        width="164" /></div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <table border="0" cellpadding="0" cellspacing="0"
                                                        class="heading_block" role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;"
                                                        width="100%">
                                                        <tr>
                                                            <td style="width:100%;text-align:center;">
                                                                <h1
                                                                    style="margin: 0; color: #555555; font-size: 23px; font-family: Helvetica Neue, Helvetica, Arial, sans-serif; line-height: 120%; text-align: left; direction: ltr; font-weight: normal; letter-spacing: normal; margin-top: 0; margin-bottom: 0;">
                                                                    <strong><span style="color: #eab308;">Greetings!</span></strong></h1>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <table border="0" cellpadding="0" cellspacing="0" class="text_block"
                                                        role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;"
                                                        width="100%">
                                                        <tr>
                                                            <td style="padding-bottom:15px;padding-top:15px;">
                                                                <div style="font-family: sans-serif">
                                                                    <div
                                                                        style="font-size: 14px; color: #555555; line-height: 1.5; font-family: Helvetica Neue, Helvetica, Arial, sans-serif;">
                                                                        <p dir="ltr"
                                                                            style="margin: 0; font-size: 14px; mso-line-height-alt: 21px;">
                                                                            <span
                                                                                style="color:#d4d4d4;font-size:14px;">{{ $data['message'] }}</span></p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <table border="0" cellpadding="0" cellspacing="0"
                                                        class="button_block" role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;"
                                                        width="100%">
                                                        <tr>
                                                            <td
                                                                style="padding-bottom:25px;padding-top:5px;text-align:left;">
                                                                <a href="{{ $data['url_link'] }}"
                                                                    style="text-decoration:none;display:inline-block;color:#0a0a0a;background-color:#eab308;border-radius:4px;width:auto;border-top:1px solid #eab308;border-right:1px solid #eab308;border-bottom:1px solid #eab308;border-left:1px solid #eab308;padding-top:12px;padding-bottom:12px;font-family:Helvetica Neue, Helvetica, Arial, sans-serif;text-align:center;mso-border-alt:none;word-break:keep-all;"
                                                                    target="_blank"><span
                                                                        style="padding-left:25px;padding-right:25px;font-size:14px;display:inline-block;letter-spacing:normal;"><span
                                                                            style="font-size: 16px; line-height: 2; word-break: break-word; mso-line-height-alt: 32px;"><span
                                                                                data-mce-style="font-size: 14px; line-height: 28px;"
                                                                                style="font-size: 14px; line-height: 28px;"><strong><span
                                                                                        data-mce-style="line-height: 24px;"
                                                                                        style="line-height: 24px;">Set New Password</span></strong></span></span></span></a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <table border="0" cellpadding="0" cellspacing="0" class="text_block"
                                                        role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;"
                                                        width="100%">
                                                        <tr>
                                                            <td style="padding-bottom:5px;">
                                                                <div style="font-family: sans-serif">
                                                                    <div
                                                                        style="font-size: 14px; color: #555555; line-height: 1.5; font-family: Helvetica Neue, Helvetica, Arial, sans-serif;">
                                                                        <p
                                                                            style="margin: 0; font-size: 14px; mso-line-height-alt: 21px;">
                                                                            <span
                                                                                style="color:#d4d4d4;font-size:14px;">Sincerely,</span><br /><span
                                                                                style="color:#d4d4d4;font-size:14px;"><strong style="color:#eab308;">Ticketoc
                                                                                    Team</strong></span></p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </th>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <table align="center" border="0" cellpadding="0" cellspacing="0" class="row row-2"
                        role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%">
                        <tbody>
                            <tr>
                                <td>
                                    <table align="center" border="0" cellpadding="0" cellspacing="0"
                                        class="row-content stack" role="presentation"
                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="600">
                                        <tbody>
                                            <tr>
                                                <th class="column"
                                                    style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; background-color: #171717;"
                                                    width="25%">
                                                </th>
                                                <th class="column"
                                                    style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; background-color: #171717;"
                                                    width="75%">
                                                    <table border="0" cellpadding="0" cellspacing="0"
                                                        class="empty_block" role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;"
                                                        width="100%">
                                                        <tr>
                                                            <td style="">
                                                                <div></div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </th>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <table align="center" border="0" cellpadding="0" cellspacing="0" class="row row-3"
                            role="presentation"
                            style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-position: center top;"
                            width="100%">
                            <tbody>
                                <tr>
                                    <td>
                                        <table align="center" border="0" cellpadding="0" cellspacing="0"
                                                class="row-content stack" role="presentation"
                                                style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-color: #171717;"
                                                width="600">
                                                <tbody>
                                                    <tr>
                                                        <th class="column"
                                                    style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-bottom: 0px solid #D4F9EF; border-left: 0px solid #D4F9EF; border-right: 0px solid #D4F9EF; border-top: 0px solid #D4F9EF; padding-left: 25px; padding-right: 25px; padding-top: 0px; padding-bottom: 15px;"
                                                    width="100%">
                                                    <table border="0" cellpadding="0" cellspacing="0" class="text_block"
                                                        role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;"
                                                        width="100%">
                                                        <tr>
                                                            <td
                                                                style="padding-bottom:10px;padding-left:10px;padding-right:10px;padding-top:20px;">
                                                                <div style="font-family: sans-serif">
                                                                    <div
                                                                            style="font-size: 12px; color: #a3a3a3; line-height: 1.2; font-family: Helvetica Neue, Helvetica, Arial, sans-serif;">
                                                                            <p
                                                                                style="margin: 0; font-size: 12px; text-align: center;">
                                                                                <a href="{{ $data['privacy_policy_link'] }}"
                                                                                    rel="noopener"
                                                                                    style="text-decoration: none; color: #eab308;"
                                                                                    target="_blank">Privacy Policy</a>  •
                                                                                 <a href="{{ $data['tc_link'] }}"
                                                                                    rel="noopener"
                                                                                    style="text-decoration: none; color: #eab308;"
                                                                                    target="_blank">Terms and Conditions</a>
                                                                            </p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <table border="0" cellpadding="10" cellspacing="0"
                                                        class="text_block" role="presentation"
                                                        style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;"
                                                        width="100%">
                                                        <tr>
                                                            <td>
                                                                <div style="font-family: sans-serif">
                                                                    <div
                                                                            style="font-size: 12px; color: #737373; line-height: 1.2; font-family: Helvetica Neue, Helvetica, Arial, sans-serif;">
                                                                            <p
                                                                                style="margin: 0; font-size: 14px; text-align: center;">
                                                                                <span style="font-size:12px;color:#737373;">© {{ $data['current_year'] }}
                                                                                    Ticketoc or its
                                                                                    affiliates.</span><br /><span
                                                                                    style="font-size:12px;color:#737373;">All rights
                                                                                    reserved.
                                                                                </span>
                                                                            </p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                        </th>
                                                    </tr>
                                                </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    <table align="center" border="0" cellpadding="0" cellspacing="0" class="row row-4"
                        role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%">
                        <tbody>
                            <tr>
                                <td>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table><!-- End -->
</body>

</html>