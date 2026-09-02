# Royal MCP-এর সঙ্গে CredoQ সংযোগ: Allowlist ও Workflow

## সংক্ষিপ্ত উত্তর

Royal MCP-এর **Allowlisted plugin options** ঘরে option name লিখলে Royal MCP ওই option-গুলোকে generic WordPress option হিসেবে update করার অনুমতি পায়। এটি CredoQ-এর পাঁচটি plugin-এর প্রতিটি admin page, booking, membership, event, seat, form, WooCommerce order বা codebase স্বয়ংক্রিয়ভাবে নিয়ন্ত্রণ করার permission দেয় না। এসব কাজের জন্য typed MCP tools প্রয়োজন।

## Royal MCP-তে কী সেট করবেন

প্রথমে Royal MCP-এর master toggle **Enable Royal MCP Integration** চালু করুন। এরপর **Allow AI to write WordPress options** চালু করুন। তারপর **Allowlisted plugin options**-এ এক লাইনে একটি করে নিচের conservative list দিন:

```text
credoq_engine_settings
credoq_booking_settings
credoq_debug_mode
credoq_e2e_runner
```

এরপর Save করুন। Royal MCP যদি প্রতিটি option-কে `sanitize_key()` দিয়ে normalize করে, তাই lowercase underscore-সহ নাম ব্যবহার করুন।

## কোন option কেন রাখা হয়েছে

| Option | ব্যবহার | AI write করা যাবে? |
|---|---|---:|
| `credoq_engine_settings` | Engine-এর সাধারণ ও security settings-এর container | সীমিতভাবে |
| `credoq_booking_settings` | Appointment/booking settings container | সীমিতভাবে; field validation দরকার |
| `credoq_debug_mode` | Debug logging toggle | হ্যাঁ, কিন্তু production-এ সতর্কতা দরকার |
| `credoq_e2e_runner` | E2E runner configuration | শুধু staging/local runner-এর জন্য |

`credoq_engine_settings`-এর মধ্যে reCAPTCHA secret key এবং অন্যান্য security value থাকতে পারে। তাই generic Royal write-এর বদলে CredoQ MCP-এর field-aware settings tool ব্যবহার করা নিরাপদ।

## কোন option allowlist-এ দেবেন না

নিচের options operational safety, credential, migration বা deletion control। এগুলো Royal MCP writable list-এ দেবেন না:

```text
credoq_smtp_settings
credoq_mcp_key_hash
credoq_mcp_key_meta
credoq_mcp_audit_log
credoq_mcp_enable_staging_orders
credoq_remove_data_on_uninstall
credoq_apt_delete_data
credoq_membership_delete_data
credoq_events_delete_data
credoq_seats_delete_data
credoq_engine_db_version
credoq_apt_db_version
credoq_membership_db_version
credoq_events_db_version
credoq_seats_db_version
```

বিশেষভাবে `credoq_mcp_enable_staging_orders` generic option write দিয়ে enable করা যাবে না। Order creation চালু করতে staging `wp-config.php`-তে `CREDOQ_MCP_STAGING_MODE=true`, non-production environment, explicit WordPress option এবং COD/BACS gateway—সবগুলো prerequisite থাকতে হবে।

## AI-এর সঠিক workflow

AI প্রথমে MCP authentication করবে এবং `tools/list` দিয়ে available tools দেখবে। তারপর read-only discovery চালাবে। Settings পরিবর্তনের ক্ষেত্রে preview/proposal তৈরি করবে; preview-তে target option, before value-এর নিরাপদ summary, requested value এবং confirmation token থাকবে। User-এর explicit approval-এর পরে `confirm=true`, `proposal_id` এবং one-time token দিয়ে mutation হবে। Token একবার ব্যবহারের পরে invalid হবে।

Booking, service এবং seat plan-এর জন্য generic Royal option update ব্যবহার করা উচিত নয়। Typed CredoQ tools দিয়ে record fetch, proposal, confirmation, mutation এবং post-change verification করতে হবে। Membership, event, provider/staff, form এবং WooCommerce data-ও একইভাবে dedicated tools দিয়ে পরিচালনা করা উচিত।

## WooCommerce test workflow

AI প্রথমে enabled payment gateways দেখবে। এরপর staging product, synthetic customer information এবং COD/BACS দিয়ে order preview করবে। User confirmation ছাড়া order তৈরি হবে না। Created order `pending` ও uncaptured থাকবে। Card বা real payment capture করা যাবে না। তারপর booking ID, order ID এবং CredoQ booking status-এর synchronization read-only verification করা যাবে।

## Skill file

Reusable AI instructions এই ফাইলে রাখা হয়েছে:

```text
automation/skills/credoq-mcp-management/SKILL.md
```

এটি Claude Desktop, অন্য MCP client, অথবা repository-aware AI workflow-এর project instruction হিসেবে ব্যবহার করা যাবে। Skill file-টি AI-কে বলে দেয় কখন generic option write করা যাবে, কখন typed MCP tool ব্যবহার করতে হবে, confirmation token কীভাবে ব্যবহার করতে হবে এবং কোন credential/destructive operation নিষিদ্ধ।

## গুরুত্বপূর্ণ সীমাবদ্ধতা

Royal MCP allowlist যোগ করলেই “যেকোনো AI” CredoQ-এর সব admin action করতে পারবে না। AI client-কে অবশ্যই Royal MCP বা CredoQ MCP server-এর authenticated endpoint-এ সংযুক্ত করতে হবে; তারপর client-এর system prompt/project skill-এ CredoQ workflow যুক্ত করতে হবে। বর্তমানে codebase পরিবর্তন, plugin install/uninstall, GitHub push, pull request এবং production deploy-এর জন্য আলাদা repository/deployment integration এবং human approval gate প্রয়োজন।
