# MoviePro Full Revised Edition

## Profile and account system

- Added guided profile setup after registration.
- Added profile completion routing after login.
- Added secure JPG/PNG/WebP avatar upload with randomized filenames and 3 MB limit.
- Added avatar replacement and removal.
- Added editable name, username, email, phone, biography and city.
- Added date of birth, gender, language, genres and marketing preferences.
- Added profile completion indicator.
- Added booking, order, review, spending and CinePoints summaries.
- Added latest-booking cards inside the profile.
- Added password change with current-password verification.
- Added profile avatar and completion status to the admin user table.
- Added profile navigation avatar on every authenticated page.

## Visual system

- Merged the global theme into the complete project.
- Added animated red/gold ambient background to every public and admin page.
- Added lightweight particle canvas, fog, aurora, perspective grid, film grain and mouse lighting.
- Fixed the circle cursor across the entire page using capture-level pointer tracking.
- Added cursor states for links, controls, cards and text fields.
- Added click ripples, magnetic buttons and reveal choreography.
- Retained 3D card tilt, hero depth, seat-map perspective, payment animation and success effects.
- Removed duplicate cursor/WebGL runtime work.
- Kept the project completely preloader-free.
- Added wider responsive navigation for the complete authenticated menu.

## Content and structure

- Added a card-rich premium homepage.
- Extracted homepage styles into a maintainable standalone CSS file.
- Expanded the About page into a complete cinema-experience presentation.
- Expanded Contact into a full support/concierge page while preserving database submission.
- Added a complete FAQ centre.
- Added profile, FAQ and support navigation to the footer and PWA shortcuts.

## Database and PWA

- Added complete profile columns to both fresh database installers.
- Added `upgrade_profile_system.sql` for existing advanced installations.
- Preserved all reservation, order, booking, payment, loyalty and admin structures.
- Updated the service-worker cache version and asset list.
- Added profile shortcuts to the web-app manifest.
