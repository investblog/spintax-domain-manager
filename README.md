# Spintax Domain Manager

A WordPress plugin that manages domains and related services from a single dashboard.

## Features

- CRUD management for projects, sites, domains and redirects.
- Import domains from Cloudflare and assign them to sites.
- Sync Cloudflare nameservers to Namecheap (single or batch).
- Handle service credentials for Cloudflare, Namecheap, Mail‑in‑a‑Box, HostTracker and Yandex.
- Two‑step email forwarding setup via Mail‑in‑a‑Box and Cloudflare.
- Redirect management using Cloudflare rulesets.
- Domain monitoring through HostTracker with hourly cron checks.
- Bulk actions for assigning sites, syncing statuses and setting abuse or block flags.
- AJAX powered tables with search, sorting and progress bars.
- Optional GraphQL integration.
- Admin UI grouping by Cloudflare zone with clearer root/subdomain context.
- Support for multi-component TLDs such as `.co.uk`, `.com.br`, and `.com.au` when determining root domains.

## Installation

Copy the plugin folder to your WordPress installation and activate it. Configure your service accounts under **Spintax Manager → Accounts** to begin managing projects and domains.

## Subdomain Handling

- Root domains and subdomains are detected using Cloudflare zone data (including multi-component TLDs like `example.co.uk`).
- The plugin sets the `is_subdomain` flag automatically and provisions placeholder DNS `A` records for subdomains when needed.
- Domain tables in the admin are grouped by Cloudflare zone so root domains and their subdomains stay together.

## Cloudflare Zones

- Domains are synced per project and rendered by zone in the admin interface.
- Each zone groups its root domain first, followed by indented subdomains with a `sub` badge for clarity.
- Redirects are separated into Main, Glue, and Hidden sections for easier review before syncing to Cloudflare.

## Security Note

- Email forwarding passwords stored in `wp_sdm_email_forwarding` are encrypted using `sdm_encrypt`/`sdm_decrypt` before saving or using them with external services.

## Requirements

- WordPress 5.8+ and PHP 7.4+.
- JSON extension enabled on the server (required for API payload handling).
- Access to Cloudflare, Namecheap, Mail‑in‑a‑Box, HostTracker, or other configured services as needed.

## Changelog

### 1.0.16
- Synced the plugin header and internal version constant to ensure assets receive the latest cache-busting value.
- Documented the release details for easier tracking of plugin updates.

### 1.0.14
- Added NS-based Yandex verification with automatic Cloudflare NS record creation.
- Success message now links to Yandex Webmaster for manual confirmation.

### 1.0.9
- Removed underline from project navigation links.
- Added a server IP selector when adding new sites.

### 1.0.10
- Updated HostTracker region mapping for languages without dedicated pools.

### 1.0.11
- Improved HostTracker pool selection for regional sites, using country-level pools when available.

### 1.0.12
- Removed unsupported HostTracker pools and mapped languages to the nearest available regions.

### 1.0.13
- Domains missing from Cloudflare are now marked as expired when syncing.

### 1.0.8
- Combined header and navigation into a single line for project pages.
