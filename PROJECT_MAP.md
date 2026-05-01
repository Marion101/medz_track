# Medz Track Project Map

This file helps you quickly find where to edit features.

## User Pages
- `dashboard.php`: user home and quick stats
- `add_medicine.php`: add medicine form
- `my_medicines.php`: medicine list, edit/delete access
- `alerts.php`: expiry and low-stock alerts
- `user_calendar.php`: expiry calendar with month/day filters
- `profile.php`: account profile, theme, member since
- `edit_profile.php`: update profile details
- `edit_medicine.php`: edit medicine details
- `user_reports.php`: redirects users/admins to correct reports page

## Admin Pages
- `admin.php`: admin overview
- `admin_users.php`: users and role management
- `admin_medicines.php`: medicine management
- `admin_logs.php`: activity logs
- `admin_reports.php`: reports, filters, print/PDF, expiry calendar

## Auth and Core
- `auth.php`: auth/session helpers, shared utility functions
- `db.php`: database connection
- `login.php`: user login
- `admin_login.php`: admin login
- `register.php`: account creation
- `forgot_password.php`: forgot password flow
- `reset_password.php`: reset password handler
- `mail_config.php`: mail settings
- `logout.php`: sign out

## Styling
- `Dashboard.css`: main app styles
- `style.css`: shared/base styles
- `Login.css`: auth page styles

## Utility
- `export.php`: export endpoint (if used by reports)
- `theme.php`: theme helper/loader

## Quick Tip
- If you need to change sidebar links for users, update:
  - `dashboard.php`, `add_medicine.php`, `my_medicines.php`, `alerts.php`, `user_calendar.php`, `profile.php`, `edit_profile.php`, `edit_medicine.php`
