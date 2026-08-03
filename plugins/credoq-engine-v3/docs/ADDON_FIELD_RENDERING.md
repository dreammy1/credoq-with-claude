# Addon Field Rendering — Frontend Bridge (Phase 1, Bug 2 fix)

This explains how `Credoq Membership` (`membership_credit`) and
`Credoq Events` (`event_registration`) — or any future addon field type —
should render correctly inside the React booking widget without the
Engine ever hardcoding their slugs.

## How it works

1. An addon registers its field type into the Engine's `Fields\Registry`
   via the existing hook:

   ```php
   add_filter( 'credoq_register_field_types', function ( $registry ) {
       $registry->register( new Membership_Credit_Field_Type() );
   } );
   ```

2. `Shortcodes::booking_form()` now loops over every field on the form and,
   for each one, asks the registry for its `Field_Type` instance and calls
   `get_frontend_render( $field )`. If the addon's field type returns a
   non-empty descriptor, it is attached to the field as `_frontend` (and
   the addon id as `_addon`) before the field list is sent to the widget
   via `data-config`.

3. `FormField.jsx` checks `field._frontend.component` **before** running
   through its built-in `text`/`select`/`checkbox`/... cases. If present,
   it renders one of four generic primitives:

   | `component` | Use for |
   |---|---|
   | `display`  | Read-only info (e.g. "Your credit balance: 3 sessions") |
   | `select`   | A dropdown — `props.options` or falls back to `field.options` |
   | `number`   | A numeric input |
   | `html`     | A raw HTML block (sanitize with `wp_kses_post()` server-side!) |

   If a type provides **no** `_frontend` descriptor and isn't a built-in
   case, FormField.jsx now falls back to a plain text input that at least
   shows the field's label as a placeholder and a
   `"Provided by the {addon} addon"` hint — never a silently blank box.

## Example: Membership_Credit_Field_Type

```php
class Membership_Credit_Field_Type extends \CredoqEngine\Abstracts\Field_Type {

    public function get_slug() : string { return 'membership_credit'; }
    public function get_label() : string { return __( 'Membership Credit', 'credoq-membership' ); }
    public function get_addon_id() : string { return 'credoq-membership'; }

    public function get_frontend_render( array $field_config ) : array {
        $balance = 0;
        if ( is_user_logged_in() ) {
            $balance = Membership_Service::get_credit_balance( get_current_user_id() );
        }
        return [
            'component' => 'display',
            'props'     => [
                'text'  => __( 'Your available credit balance', 'credoq-membership' ),
                'value' => $balance,
            ],
        ];
    }
}
```

## Example: Event_Registration_Field_Type

```php
class Event_Registration_Field_Type extends \CredoqEngine\Abstracts\Field_Type {

    public function get_slug() : string { return 'event_registration'; }
    public function get_label() : string { return __( 'Event Registration', 'credoq-events' ); }
    public function get_addon_id() : string { return 'credoq-events'; }

    public function get_frontend_render( array $field_config ) : array {
        $options = array_map( function ( $event ) {
            return [ 'label' => $event->title, 'value' => $event->id ];
        }, Event_Repository::upcoming() );

        return [
            'component' => 'select',
            'props'     => [
                'options'     => $options,
                'placeholder' => __( 'Choose an event…', 'credoq-events' ),
            ],
        ];
    }

    // Standalone WC checkout / price contribution for the selected event
    // can reuse wc_contribution() exactly like Builtin_Types::Field_Select.
}
```

Neither addon requires any change to `Submission_Handler`, `Registry`, or
`FormField.jsx` beyond what ships in this release — they only need to
implement `get_frontend_render()` (and, for value handling, `sanitize()` /
`validate()` / `price_contribution()` / `wc_contribution()` as usual).
