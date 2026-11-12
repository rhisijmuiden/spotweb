# Spotweb house style

This is the visual identity of this Spotweb build. It is implemented in
`templates/modern/css/brand.css`; change the tokens there and every screen
follows.

## Principles

- **Calm and dark first.** Dark is the default look, light follows the same
  structure. The theme follows the operating system unless the user picks one.
- **One accent.** Violet marks what is active or clickable. Status colours
  (green, amber, red, blue) are only used for status.
- **Menus on the left.** Navigation, the admin centre and the user menu all
  use a left sidebar with the content on the right.
- **Quiet surfaces.** Flat surfaces with a 1px border; no gradients, no heavy
  shadows. A raised shadow only on hover and for menus and dialogs.

## Colour tokens

| Token | Dark | Light | Use |
|---|---|---|---|
| `--sw-bg` | `#0B0F14` | `#F5F6F9` | page background |
| `--sw-surface` | `#10151C` | `#FFFFFF` | sidebar, cards, panels |
| `--sw-surface-2` | `#161D27` | `#F0F2F6` | hover, table header, inputs |
| `--sw-border` | `#1E2733` | `#DDE2EA` | 1px borders and dividers |
| `--sw-text` | `#E6EDF5` | `#111827` | main text |
| `--sw-text-2` | `#C2CCD8` | `#3F4A5A` | secondary text, menu items |
| `--sw-muted` | `#8B98A9` | `#5B6676` | labels, meta data |
| `--sw-accent` | `#8B7CFF` | `#6D5DF5` | primary buttons, active item |
| `--sw-accent-soft` | `#1F1B3D` | `#EEEBFF` | selected background |
| `--sw-success` | `#34D399` | `#0F9F6E` | ok / active |
| `--sw-warning` | `#FBBF24` | `#B7791F` | waiting / attention |
| `--sw-danger` | `#F87171` | `#D93B3B` | errors / destructive |
| `--sw-info` | `#93C5FD` | `#2563EB` | API / informational |

Text colours meet WCAG AA contrast on their surfaces in both themes.

## Typography

- **Inter** (variable, self-hosted in `templates/modern/fonts/`) for all UI text, 14px base.
- **Space Grotesk** 500–700 for page titles and large numbers.
- Section labels: 11px, semibold, uppercase, 0.08em letter spacing.
- Both fonts are licensed under the SIL Open Font License (see the license files next to the fonts).

## Shape and spacing

- Radius: 12px for cards and panels, 8px for buttons, inputs and menu items, pills fully rounded.
- Spacing on a 4px grid; panels use 20–24px padding, page gutters 32–40px.
- Top bar 56px high; left sidebar 248px wide.
- Buttons and inputs are at least 36px high (44px on touch screens).

## Components

- **Primary button:** accent background, dark text (dark theme) / white text (light theme).
- **Secondary button:** surface background with a strong border.
- **Menu item:** transparent, hover `--sw-surface-2`, active `--sw-accent-soft` with a 3px accent bar on the left.
- **Status pill:** soft status background with the status colour as text.

The design sketch is the reference for new screens (spots overview, admin
centre, user menu with history, statistics).
