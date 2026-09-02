# Phase 2 Audit Findings — Functional Gaps & Bugs

## 1. Membership Accounting Bug (Plan ID Mismatch)
**Plugin**: `credoq-events-v3`  
**File**: `includes/Fields/Field_Event.php`  
**Issue**: When deducting credits for an event registration via the Forms Builder flow, the code passes `0` as the `plan_id` to the ledger. This causes `Membership_Service::get_balance($user_id, $plan_id)` to skip these entries, resulting in an incorrect (over-inflated) credit balance. The user effectively gets free registrations if they have a specific plan selected.
**Fix**: Extract the `plan_id` from the submission payload (it's stored in the `membership_credit` field) and pass it to the ledger.

## 2. Missing Credit Refund in Legacy Event Flow
**Plugin**: `credoq-events-v3`  
**File**: `includes/Event_Service.php`  
**Issue**: The `cancel()` method releases seats but does NOT refund membership credits. Unlike the Appointments plugin (which was recently patched), this legacy flow leaves users without their spent credits when a registration is cancelled.
**Fix**: Implement the same `Membership_Service::refund_credit` pattern used in `Booking_Service.php`.

## 3. Ambiguous Waitlist Integration
**Plugin**: `credoq-appointments`  
**File**: `includes/Booking_Service.php`  
**Issue**: `Waiting_List_Repository::offer_next()` is called on cancellation, but there's no automated system to actually convert that "offer" into a booking or notify the user via the Engine's mailer. It's a "silent" offer that might be missed by customers.
**Verification Needed**: Trace the `offer_next` mailer integration.

## 4. SQL Status Exclusion Gap
**Plugin**: `credoq-engine-v3`  
**File**: `includes/Admin/Reports_Page.php`  
**Issue**: The `render_overview` SQL count logic for `members`, `bookings`, and `events` does not seem to exclude 'cancelled' or 'refunded' statuses in its raw KPI counts. This makes the dashboard reports misleadingly high.
**Fix**: Add `WHERE status NOT IN ('cancelled', 'refunded', 'failed')` to the overview queries.

## 5. Dead Notification Hook
**Plugin**: `credoq-appointments`  
**File**: `includes/Waiting_List_Repository.php`  
**Issue**: The `offer_next()` method attempts to call `\CredoqMembership\Notification_Service::add()`, which does not exist in any plugin. This causes a "silent" failure when a waitlist offer is sent.
**Fix**: Redirect this to the Engine's `\CredoqEngine\Mail\Notifications::create()` and use the Engine's `Mailer` for the email instead of a raw `wp_mail` call.

## Phase 2 Status: Identification Complete
I have identified 5 major functional gaps/bugs during the audit. I will now transition to **Phase 3: Implementation (Fixes)** to permanently resolve these issues.
