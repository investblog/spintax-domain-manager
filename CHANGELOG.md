## v1.0.19
- Added encryption for email forwarding passwords.
- Added domain grouping by Cloudflare zone in admin UI.
- Split redirect interface into Main / Glue / Hidden sections.
- Fixed subdomain detection logic.
- Added support for multi-component TLDs (example.co.uk, example.com.br).
- Added automatic DB migration for missing fields (is_subdomain, hosttracker_task_id).
- Improved handling of Cloudflare DNS placeholder records.
