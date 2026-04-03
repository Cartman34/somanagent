# SoManAgent UI Usage Guidelines

> See also: [Overview](overview.md) · [Key Concepts](concepts.md) · [Workflows](workflows.md)

## Purpose

This document defines the shared UI usage rules for SoManAgent.

Its goal is to help product and development make the same decisions when building or evolving screens:

- which reusable component to use
- when to use it
- which visual priority it should have
- which accessibility and content rules apply

This is a usage guide, not a marketing brand book.

## Scope

This document covers:

- page structure and information hierarchy
- reusable component usage
- action priority rules
- responsive and accessibility baseline
- theme integration rules

This document does not define:

- detailed palette decisions for each theme
- free-form visual exploration
- one-off exceptions that bypass the shared UI system

Theme-specific colors, surfaces, and atmosphere remain defined by the theme system and mockups.

## Design Principles

The interface should follow these principles on every screen:

1. **One obvious primary action**
   A user should immediately understand the main action of a page, form, or dialog.

2. **Operational readability first**
   Structure, labels, and state feedback take priority over decorative styling.

3. **Consistent patterns over local invention**
   Similar tasks must use the same interaction model across the application.

4. **Low cognitive load**
   Screens should expose the right amount of information for the task, not every available detail at once.

5. **Explicit system feedback**
   Loading, success, warning, and error states must be visible and understandable.

6. **Themes change expression, not behavior**
   Themes may change the visual atmosphere, but not the meaning or hierarchy of components.

## Information Hierarchy and Page Structure

### Application shell

The shell establishes stable navigation and should remain predictable:

- the sidebar is used for persistent navigation
- the top bar is used for global context or utilities
- the main content area is used for page-specific information and actions

### Page hierarchy

Each page should be structured in this order:

1. page header
2. primary content
3. secondary content
4. destructive or low-priority actions

The page header should answer three questions immediately:

- where am I?
- what is this page about?
- what is the main thing I can do here?

### Grouping and density

Use grouping to clarify meaning, not to decorate content.

- use sections when content belongs to the same reading flow
- use cards or panels when a block needs separation, status emphasis, or action framing
- avoid deep nesting of bordered containers
- keep the densest layouts for operational screens such as boards, logs, and activity views
- keep more breathing room for setup, form, and detail pages

## Reusable Components Catalog

Each reusable component should be evaluated with the same questions:

- **Purpose**: what job the component does
- **Use when**: valid usage contexts
- **Do not use when**: misuse or overuse cases
- **Variants**: allowed forms and visual levels
- **Priority**: how strong the component should appear
- **Content rules**: labels, icons, helper copy, and length constraints
- **Accessibility rules**: keyboard, focus, naming, contrast, and non-color feedback

The following sections define the minimum expected guidance for high-reuse components.

## Buttons

Buttons represent intent and priority. Their variant must match the importance and risk of the action.

### Primary button

**Purpose**
The main action of the current scope.

**Use when**

- submitting a form
- creating or saving an entity
- confirming the main next step in a flow

**Do not use when**

- multiple actions compete for primary emphasis in the same scope
- the action is destructive
- the action is secondary, optional, or contextual

**Rules**

- use at most one primary button per main action area
- place it where the user expects completion or forward progress
- label it with a clear verb, not a vague confirmation

### Secondary button

**Purpose**
A useful action that should remain available without competing with the primary action.

**Use when**

- canceling, going back, editing, or opening a side workflow
- offering an alternate but non-dominant path

**Do not use when**

- the action should drive the user’s attention first
- the action is destructive and needs stronger signaling

### Danger button

**Purpose**
A destructive or hard-to-reverse action.

**Use when**

- deleting an entity
- triggering a risky reset or irreversible state change

**Do not use when**

- the action is only important, not destructive
- a warning badge or confirmation message would be sufficient

**Rules**

- pair with explicit confirmation when the outcome is irreversible or high impact
- the label must describe the consequence directly

### Ghost or low-emphasis button

**Purpose**
A contextual action that should stay available without adding visual noise.

**Use when**

- inline controls
- utility actions in dense views
- optional local interactions

**Do not use when**

- the action is critical to task completion
- the action needs to stand out in the page hierarchy

### Icon-only button

**Purpose**
A compact action for a well-known interaction.

**Use when**

- the icon meaning is obvious in context
- space is constrained and the action is familiar

**Do not use when**

- the meaning depends on interpretation
- the action is primary or destructive without supporting text

**Rules**

- always provide an accessible name
- keep icon-only usage consistent for repeated actions

## Page Headers

Page headers frame the screen and orient the user.

Use a page header to define:

- title
- optional description
- high-priority actions
- optional refresh action when data reload is meaningful

Guidelines:

- the title should be short and stable
- the description should add context, not repeat the title
- only place an action in the header if it affects the full page scope
- avoid crowding the header with many equal-priority actions

## Cards, Panels, and Sections

These components create structure and emphasis.

### Use sections when

- content belongs to the main reading flow
- separation can be expressed through spacing and headings

### Use cards or panels when

- a block needs visual containment
- a block has its own actions or status
- a block represents a distinct entity or sub-context

### Do not

- wrap every block in a card by default
- combine strong borders, heavy shadows, and dense spacing everywhere
- create nested visual containers without clear semantic value

## Forms

Forms should guide completion with minimum friction.

### Structure

- group fields by user intent, not by backend structure
- place related fields together
- keep the primary submit action easy to find
- keep secondary or cancel actions visually subordinate

### Validation and help

- show field-level errors near the relevant field
- use helper text only when it prevents mistakes
- required fields must be identifiable without relying only on color
- preserve user input when validation fails

### Actions

- the submit action is usually primary
- cancel, close, or back actions are secondary
- destructive form actions must be isolated from standard submit actions

## Status Components

Status components communicate system meaning quickly.

### Badges

Use badges for compact categorical status:

- workflow state
- agent state
- environment or severity labels

Badges should not be the only place where critical meaning exists if the surrounding context depends on it.

### Alerts and inline feedback

Use alerts or inline messages when the system needs to explain:

- a blocking error
- a warning before action
- a success outcome worth acknowledging
- a notable informational state

Rules:

- color must support meaning, not replace it
- the message should explain consequence and next step when relevant

## Dialogs and Confirmations

Dialogs interrupt flow and must be justified.

Use a dialog when:

- the user must confirm a risky action
- extra focused input is needed before continuing
- the task benefits from a short isolated decision surface

Do not use a dialog when:

- a normal page or drawer provides better continuity
- the content is long, exploratory, or multi-step

Guidelines:

- keep one clear primary action
- include a predictable cancel path
- use confirmation copy that states the consequence directly

## Data Display Components

Choose the display mode that best supports the user task.

### Table

Use when users need:

- comparison across columns
- scan efficiency across many rows
- repeated structured attributes

### List

Use when users need:

- easier reading on variable-height content
- lightweight navigation through records
- more descriptive content than a table allows

### Board

Use when users need:

- workflow stage visibility
- movement or progression between states
- a spatial view of task distribution

### Activity feed

Use when time order and chronology matter more than comparison.

### Empty state

Use empty states to explain:

- what is missing
- why it matters
- what action to take next

### Loading feedback

Use subtle refresh feedback for small reloads and stronger overlays only when interaction must be blocked.

## Cross-Component Rules

These rules apply across the UI system:

- each scope should have one obvious primary action
- destructive actions must not visually compete with safe completion actions
- button labels should start with explicit verbs when possible
- repeated icons should keep the same meaning everywhere
- disabled states should still remain understandable
- loading states should preserve context whenever possible
- hover, focus, and active behavior should feel consistent across reusable controls

## Responsive and Accessibility Baseline

All screens and reusable components must meet the same minimum baseline.

### Responsive rules

- layouts must remain usable on mobile, tablet, and desktop
- actions should remain reachable without horizontal scrolling in standard workflows
- dense desktop patterns may adapt to stacked or simplified layouts on smaller screens

### Accessibility rules

- interactive elements must be reachable by keyboard
- focus must remain visible in all themes
- icon-only controls must expose an accessible name
- contrast must remain sufficient across themes
- critical meaning must never rely only on color
- error states must identify the issue and, when possible, the correction path

## Theme Integration

SoManAgent supports multiple themes, but themes do not redefine component usage.

Themes may change:

- color palette
- surface treatment
- corner radius
- body font family
- ambient styling

Themes must not change:

- component semantics
- action hierarchy
- information hierarchy
- accessibility expectations
- usage rules defined in this document

Implementation references:

- `frontend/src/index.css`
- `frontend/src/hooks/useTheme.ts`
- `doc/mockups/`

## Applied Patterns

The guide should be applied consistently in common screen types.

### Standard list page

- page header with one primary action if creation is the main goal
- filters and utilities kept secondary
- table or list chosen based on comparison needs
- empty state includes a clear next action

### Detail page

- title and summary first
- entity actions grouped by priority
- metadata secondary to the main operational content

### Standard form

- grouped fields
- one submit action
- one secondary escape path
- inline validation and clear completion feedback

### Activity or logs view

- dense layout allowed
- scanning and chronology prioritized
- status and severity must remain easy to parse

### Destructive confirmation

- direct consequence in title or body copy
- danger action clearly separated
- cancellation easy and safe

## Review Checklist

When adding or changing a screen, validate the following:

- Is the main action obvious?
- Are component choices aligned with their intended use?
- Is any component visually stronger than its real importance?
- Are destructive actions sufficiently explicit?
- Does the screen remain understandable in a different theme?
- Does the UI remain usable with keyboard and clear focus states?
- Is feedback provided for loading, error, and success states?
