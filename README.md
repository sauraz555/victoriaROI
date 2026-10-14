# Victorian Points Test lead funnel

A lead-generation page for interlace.com.au built around Victoria's new Victorian Points Test (VPT) for 190 and 491 state nomination, published on 13 October 2026.

Visitors answer 9 questions, then enter their details to unlock their score. Each lead is emailed to `sales@interlace.com.au` through Resend, with the score, every answer and routing tags. If the visitor gives an email, they get their own score report too.

## What's in this repo

| Path | What it is |
| --- | --- |
| `page/victorian-points-test.html` | The page. Paste into a WordPress **Custom HTML** block. |
| `plugin/interlace-vpt-leads/interlace-vpt-leads.php` | WordPress plugin that receives the form and sends the emails via Resend. |

## Setup

1. **Install the plugin.** Zip the `plugin/interlace-vpt-leads` folder and upload it in WordPress → Plugins → Add New → Upload, then activate it. (Or copy the folder into `wp-content/plugins/`.)
2. **Add the Resend API key** to `wp-config.php`, above the "That's all, stop editing!" line:
   ```php
   define( 'INTERLACE_RESEND_API_KEY', 're_xxxxxxxxxxxxxxxx' );
   ```
   The interlace.com.au domain is already verified in Resend, so emails send from `leads@interlace.com.au`.
3. **Create the page.** Pages → Add New, title "Victorian Points Test", slug `victorian-points-test`. Add a Custom HTML block and paste in `page/victorian-points-test.html`. Publish.
4. **Test it.** Complete the checker with your own details and confirm the email arrives at sales@interlace.com.au.

If WordPress strips the `<script>` when saving, a security plugin or user role is filtering it. Paste it with a code-snippet plugin such as WPCode instead.

### Optional settings (`wp-config.php`)

```php
define( 'INTERLACE_VPT_TO',   'sales@interlace.com.au' );                         // where leads go
define( 'INTERLACE_VPT_FROM', 'Interlace VPT Checker <leads@interlace.com.au>' ); // sender
define( 'INTERLACE_VPT_GHL_WEBHOOK', 'https://services.leadconnectorhq.com/hooks/...' ); // also push leads to GoHighLevel
```

## How the funnel works

1. **Hook.** The headline uses the news: Victoria changed the rules, there's no pass mark, and every ROI must be lodged again.
2. **Commitment ladder.** Easy questions first, one per screen, auto-advancing. The score stays hidden throughout; the side panel shows profile progress.
3. **Gate.** Step 10 says "Your result is ready", shows a blurred score and three personalised insights, then asks for first name, mobile/WhatsApp and an optional email. A WhatsApp link is offered for people who won't fill in a form.
4. **Reveal and offer.** Score, "points you could still gain", the booking CTA, a pre-written WhatsApp message with their score, then the full breakdown.
5. **Education.** Fees, invitation streams, old vs new rules, full points tables and priority occupations sit below to handle objections and support SEO.

If the lead endpoint is down, the visitor still sees their score and is pushed to WhatsApp, so no lead is lost to an outage.

## Lead email to sales

Every email is clearly labelled as coming from the **Victorian ROI form**: in the subject, the header bar and the first line.

**Subject:** `[Victorian ROI Form] 🔥 Ram · 622/950 · Healthcare · In Victoria · Returning`
(🔥 = hot lead; location comes from the visitor's time zone, otherwise their phone's country code.)

**Email sections, in order:**

1. **At a glance:** score, lead grade, visa, age, English, industry and Victorian earnings.
2. **Contact:** one-tap Call, WhatsApp (with a greeting already written) and Email buttons; phone country; the lead's local time and whether it's a good time to call; opening-alert opt-in.
3. **Suggested talking points:** priority stream, biggest point gain, partner and age issues, the 65-point federal check, and services that fit (for example PTE coaching or skills assessment).
4. **Points they could still gain:** exactly what the visitor saw on the page.
5. **Their answers and points:** the full breakdown.
6. **How they found us:** traffic source (UTM campaign, Facebook/Google ad, Google search, referral or direct), device and app, browser language, time spent on the checker, number of visits, page, time received and a lead reference such as `VIC-261014-550D`.

**Lead grades**

| Grade | Rule |
| --- | --- |
| HOT – call today | Under 45, and a score of 600+ or working in Victoria in a priority industry |
| WARM – call within 24h | Score 450+, or a priority industry |
| NURTURE | Everyone else under 45 |
| Not eligible (45+) | Age 45 or over; offer other pathways |

**Returning lead:** if the same phone or email used the checker in the last 90 days, the email says so and gives the last date.

All of this is collected in the background. The visitor only ever types their name, mobile and (optionally) email. The score is recalculated on the server, so the browser can't send a fake score.

**CRM tags:** `vpt-checker`, `victorian-roi-form`, a score band (`vpt-700plus`, `vpt-550-699`, `vpt-400-549`, `vpt-under-400`), `vic-priority-<industry>` or `vic-non-priority`, `vic-working-in-vic` or `vic-not-working-in-vic`, `visa-190`/`visa-491`, `vic-opening-alert`, `returning-lead`, `hot-lead`, `age-ineligible`. The same fields, plus UTM values, go to GoHighLevel if the webhook is set.

### Tracking ad campaigns

Add UTM tags to every ad link so the source shows in the email, for example:
`https://interlace.com.au/victorian-points-test/?utm_source=facebook&utm_medium=paid&utm_campaign=vic-vpt-launch`
Facebook, Google, Microsoft and TikTok ad clicks are recognised even without UTMs. The first source is remembered for 30 days, so a visitor who clicks an ad and comes back later is still credited to that ad.

## Spam protection

A hidden honeypot field, a limit of 5 submissions per IP per hour, and server-side validation of every field.

## Analytics events

Pushed to `dataLayer` (GA4 via GTM) and the Meta pixel if they're on the page:

| Event | When |
| --- | --- |
| `ivpt_start` | First question answered |
| `ivpt_step` | Each question completed (`step`, `step_name`) |
| `ivpt_gate_view` | Reached the unlock form |
| `ivpt_lead` | Details submitted (Meta: `Lead`) |
| `ivpt_lead_error` | Submission failed; score shown anyway |
| `ivpt_reveal` | Score shown |
| `ivpt_whatsapp` | WhatsApp clicked (Meta: `Contact`) |
| `ivpt_book` | Book An Appointment clicked on the result |

The drop-off between `ivpt_gate_view` and `ivpt_lead` is the number to watch.

## Source

Points tables, fees and priority occupations: Victorian Government, [Live in Melbourne – Victorian Points Test](https://liveinmelbourne.vic.gov.au/migrate/skilled-migration-visas/vpt) and [2026–27 program update](https://liveinmelbourne.vic.gov.au/news-events/news/2026/update-victorias-skilled-visa-nomination-program-2026-27) (13 October 2026). Update the tables in both the page and the plugin if Victoria changes them.
