# Changelog Summary

## Simplified Email Forwarding Setup
Replaced the original four-step email routing process with a streamlined two-step flow. The new process creates a Mail-in-a-Box email and then a custom Cloudflare address. Remaining steps (enabling routing and setting catch-all) are provided as on-screen instructions.

## Final Instructions UI Added
An "Almost done!" section has been integrated into the email forwarding modal. It displays clear instructions and a dynamic link to the Cloudflare Routing page, generated via a new AJAX handler that retrieves the Cloudflare account details.

## Enhanced AJAX Handlers
A new AJAX endpoint (`sdm_ajax_get_zone_account_details`) was added to fetch account details based on the domain’s stored zone ID, ensuring the proper dynamic link is assembled without modifying the existing domain table.

## UI & Code Integration
The new email forwarding functionality was integrated into the existing domain management UI without affecting core features such as sorting, mass actions, and domain deletion.

...

## 2DO
Add a rule to redirect requests with parameters (http.request.uri.query wildcard r"*")
