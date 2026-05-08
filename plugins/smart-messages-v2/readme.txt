=== Smart Messages ===
Contributors: tagglefish
Tags: whatsapp, sms, notifications, leads, messaging
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WhatsApp and SMS messages to leads and customers from Smart Forms bookings.

== Description ==

Smart Messages connects your Smart Forms lead and booking system to WhatsApp Cloud API and Twilio SMS so you can automatically notify customers at every stage of the booking process.

**Features:**

* Automatic WhatsApp messages when leads come in
* Booking request, confirmation, denial, and time-suggestion notifications
* 24-hour appointment reminders via scheduled cron
* SMS fallback via Twilio when WhatsApp delivery fails
* Contractor notifications for new booking requests
* Customizable WhatsApp template names per trigger
* Message log showing delivery status for all sent messages

**Requires the Smart Forms plugin to provide lead and booking data.**

== Third-Party Services ==

This plugin connects to the following external services to send messages. By using this plugin you agree to the terms and privacy policies of these services.

**WhatsApp Cloud API (Meta)**

This plugin sends WhatsApp template messages to customers and contractors via the WhatsApp Cloud API hosted by Meta Platforms, Inc. Messages are sent to the endpoint `https://graph.facebook.com/v18.0/` using credentials you configure (Access Token and Phone Number ID).

* Service URL: [https://developers.facebook.com/docs/whatsapp/cloud-api](https://developers.facebook.com/docs/whatsapp/cloud-api)
* Terms of Service: [https://www.whatsapp.com/legal/terms-of-service](https://www.whatsapp.com/legal/terms-of-service)
* Privacy Policy: [https://www.whatsapp.com/legal/privacy-policy](https://www.whatsapp.com/legal/privacy-policy)

**Twilio SMS API**

When WhatsApp delivery fails and SMS fallback is enabled, this plugin sends SMS messages via the Twilio REST API using credentials you configure (Account SID, Auth Token, and phone number). Messages are sent to the endpoint `https://api.twilio.com/2010-04-01/`.

* Service URL: [https://www.twilio.com/docs/sms](https://www.twilio.com/docs/sms)
* Terms of Service: [https://www.twilio.com/en-us/legal/tos](https://www.twilio.com/en-us/legal/tos)
* Privacy Policy: [https://www.twilio.com/en-us/legal/privacy](https://www.twilio.com/en-us/legal/privacy)

== Installation ==

1. Upload the `smart-messages-v2` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to "Smart Messages" in the admin menu.
4. Enter your WhatsApp Cloud API Access Token and Phone Number ID.
5. Optionally configure Twilio credentials for SMS fallback.
6. Set your contractor phone number to receive booking notifications.
7. Configure which triggers should send messages and set your WhatsApp template names.

== Frequently Asked Questions ==

= Do I need a WhatsApp Business account? =

Yes. You need a Meta Business account with WhatsApp Cloud API access and pre-approved message templates configured in Meta Business Suite.

= What happens if WhatsApp fails to send? =

If you have configured Twilio SMS credentials and enabled the SMS fallback option, the plugin will automatically attempt to send the message as an SMS instead.

= Does this plugin work without Smart Forms? =

No. Smart Messages relies on hooks fired by the Smart Forms plugin to trigger messages when leads are created and bookings are managed.

== Changelog ==

= 2.0.0 =
* Added Twilio SMS fallback support.
* Added contractor notification messages.
* Added 24-hour appointment reminders via cron.
* Added message log with delivery status.
* Full internationalization support.
* WordPress.org coding standards compliance.
