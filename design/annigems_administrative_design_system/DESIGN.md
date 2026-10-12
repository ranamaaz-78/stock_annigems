---
name: Annigems Administrative Design System
colors:
  surface: '#f6faff'
  surface-dim: '#d2dbe4'
  surface-bright: '#f6faff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#ecf5fe'
  surface-container: '#e6eff8'
  surface-container-high: '#e0e9f2'
  surface-container-highest: '#dbe4ed'
  on-surface: '#141d23'
  on-surface-variant: '#4f4535'
  inverse-surface: '#293138'
  inverse-on-surface: '#e9f2fb'
  outline: '#817563'
  outline-variant: '#d3c4af'
  surface-tint: '#7b5800'
  primary: '#785600'
  on-primary: '#ffffff'
  primary-container: '#986d00'
  on-primary-container: '#fffbff'
  inverse-primary: '#f7bd48'
  secondary: '#5d5e61'
  on-secondary: '#ffffff'
  secondary-container: '#e2e2e5'
  on-secondary-container: '#636467'
  tertiary: '#5a5c5d'
  on-tertiary: '#ffffff'
  tertiary-container: '#737576'
  on-tertiary-container: '#fcfdfe'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffdea6'
  primary-fixed-dim: '#f7bd48'
  on-primary-fixed: '#271900'
  on-primary-fixed-variant: '#5d4200'
  secondary-fixed: '#e2e2e5'
  secondary-fixed-dim: '#c6c6c9'
  on-secondary-fixed: '#1a1c1e'
  on-secondary-fixed-variant: '#454749'
  tertiary-fixed: '#e1e3e4'
  tertiary-fixed-dim: '#c5c7c8'
  on-tertiary-fixed: '#191c1d'
  on-tertiary-fixed-variant: '#454748'
  background: '#f6faff'
  on-background: '#141d23'
  surface-variant: '#dbe4ed'
typography:
  display:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.02em
  headline-sm:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-caps:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.05em
  data-mono:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '500'
    lineHeight: 18px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  sidebar-width: 260px
  topbar-height: 64px
  gutter: 1rem
  container-padding: 1.5rem
  stack-compact: 0.5rem
  stack-dense: 0.25rem
---

## Brand & Style
This design system bridges the gap between high-end jewelry aesthetics and high-performance inventory management. The brand personality is prestigious yet pragmatic—evoking the luxury of the jewelry industry while providing the rigorous precision required for a professional back-office stock and invoicing tool. 

The visual style is **Corporate Modern with Minimalist accents**. It utilizes a sophisticated "Editorial Professional" aesthetic: high-quality typography, a restrained color palette, and a focus on information density. The interface prioritizes utility and clarity to ensure that staff can manage high-value assets with absolute accuracy. The emotional response is one of trust, exclusivity, and systematic efficiency.

## Colors
The palette is anchored by **Deep Gold (#B8860B)**, used sparingly for primary actions and highlights to maintain a premium feel without sacrificing legibility. 

- **Primary Canvas:** The main workspace uses an off-white background to reduce eye strain during long shifts, while surfaces (cards, panels) are pure white with subtle borders.
- **Structural Chrome:** The Sidebar and Topbar utilize a "Deep Charcoal" (#1A1C1E), creating a clear mental model between navigation (dark) and data manipulation (light).
- **Functional Semantics:** Status indicators use high-chroma colors for instant recognition in dense tables. "Available" is a rich forest green, "Reserved" uses a warm amber, "Sold" is a clean crimson, and "Transferred" is a professional cobalt blue.

## Typography
The system relies exclusively on **Inter** to ensure maximum legibility at small sizes. The scale is intentionally tight to support data-dense environments.

- **Data Presentation:** All numeric values in tables should use `data-mono` (Inter with tabular lining enabled) to ensure columns of figures align perfectly for easy visual scanning.
- **Hierarchy:** Use `label-caps` for section headers within forms and table headers to create a clear structural distinction from user-generated content.
- **Scaling:** For mobile views, the 14px body size remains the floor to ensure touch targets and readability are maintained, while headlines scale down slightly.

## Layout & Spacing
The layout follows a **Fixed-Sidebar Fluid-Content** model. The left sidebar is a constant anchor for global navigation, while the top bar handles contextual actions like branch switching and user profiles.

- **Grid:** Content is housed in a flexible grid system. In the main area, use a 12-column system for forms. 
- **Density:** Spacing is compact. Use `stack-dense` (4px) for the gap between labels and inputs, and `stack-compact` (8px) for related grouping of elements.
- **Tables:** Rows should have a fixed height (e.g., 40px or 48px) to maximize the amount of inventory visible on a single screen without scrolling. Use 16px horizontal padding within cells.

## Elevation & Depth
This design system uses a **Low-Contrast Outline** approach rather than traditional shadows to maintain a clean, professional "admin" look.

- **Layering:** Depth is communicated through color and borders. The background is `#F8F9FA`, and cards/containers are pure `#FFFFFF` with a 1px border of `#E9ECEF`.
- **Interaction:** Shadows are reserved only for "floating" elements like dropdown menus, date pickers, and modals. These shadows should be tight and neutral (e.g., `0 4px 12px rgba(0,0,0,0.08)`).
- **Sidebar:** The dark sidebar uses color depth rather than shadow to sit "behind" the main content area, creating a clear z-index distinction.

## Shapes
The shape language is **Soft and Precise**. A 4px to 8px radius is applied globally to balance the clinical nature of the data with the premium brand feel.

- **Standard Elements:** Buttons, input fields, and small cards use a 4px (0.25rem) radius.
- **Structural Containers:** Larger dashboard widgets and main content containers use an 8px (0.5rem) radius.
- **Status Pills:** Badges used in tables use a fully rounded (pill) shape to distinguish them from interactive buttons.

## Components
- **Buttons:** Primary buttons use the Deep Gold background with white text. Secondary buttons use a white background with a 1px border and charcoal text. Sizes are compact (32px-36px height).
- **Data Tables:** Headers are sticky with a light gray background (#F1F3F5). Row hover states use a subtle tint (#F8F9FA). Numeric columns are always right-aligned.
- **Status Badges:** Compact pills with a light background tint of the status color and a dark foreground text (e.g., "Available" uses a 10% green background with 100% green text).
- **Input Fields:** Labels sit directly above the input in `label-caps`. Inputs have a 1px border that turns Deep Gold on focus.
- **Inventory Cards:** Used for high-level stock overviews; they feature a small thumbnail of the jewelry piece (1:1 ratio) on the left with essential metadata (SKU, Carat, Price) on the right.
- **Branch Selector:** A specialized dropdown in the Topbar that allows for instant switching between boutique locations or warehouses.