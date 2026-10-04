# Request: register a SAML service provider for the Macrolab website

*Draft to send to TU Delft ICT. Replace every `<...>` placeholder before sending.
Written for ICT identity-management staff, not for the lab.*

*Note for the lab, delete before sending: the SSO side of the application is not
built yet (README.md, "Stage 2"), so the metadata URL below answers 404 until it
is. Send this when that work is close, or tell ICT when the metadata will be
available.*

## Summary of the request

We ask for the web application below to be registered as a SAML 2.0 service
provider with the TU Delft identity provider at `login.tudelft.nl`, so that lab
members can sign in with their netID.

## The application

| | |
|---|---|
| Name | Macrolab website |
| Purpose | Reserving time on the lab equipment in `<faculty / department / lab>`, and time registration for its lab technicians |
| URL | `https://macrolab.citg.tudelft.nl/` |
| Owner / contact | `<name>`, `<email>`, `<phone>` |
| Hosted on | TU Delft LAMP hosting (Plesk), `<server or hosting request reference>` |
| Expected users | `<n>` staff, PhD candidates and students of `<group>` |
| Software | Purpose-built PHP application; SAML handled by the `onelogin/php-saml` library (v4.3) |

## Service provider details

| | |
|---|---|
| entityID | `https://macrolab.citg.tudelft.nl/auth/saml/metadata` |
| Metadata URL | `https://macrolab.citg.tudelft.nl/auth/saml/metadata` (signed, served over HTTPS) |
| Assertion Consumer Service | `https://macrolab.citg.tudelft.nl/auth/saml/acs`, binding HTTP-POST |
| Single Logout Service | `https://macrolab.citg.tudelft.nl/auth/saml/sls`, binding HTTP-Redirect |
| NameID format requested | `urn:oasis:names:tc:SAML:2.0:nameid-format:persistent` |
| Signing / digest algorithm | RSA-SHA256 / SHA-256 |
| AuthnRequests signed | Yes |
| Assertions must be signed | Yes (we reject unsigned assertions) |

The SP certificate is published in the metadata at the URL above.

## Attributes we need released

The application identifies a user by **netID**. Because the IdP issues a
*persistent* (opaque, pairwise) NameID, the netID cannot be derived from it, so
we need it as an attribute.

| Purpose | Attribute requested | Required? |
|---|---|---|
| Account identity - matched against a lab-maintained allowlist | **netID** (`uid`) | **Required** |
| Shown in the calendar so colleagues can see who booked a slot | `displayName` | Optional but wanted |
| Contact address for the lab administrator | `mail` | Optional |

If your standard release uses different attribute names or OIDs, please tell us
which ones - the application maps attribute names in configuration, so any
naming works without a code change.

## Data we store

Only what the function needs:

- netID, display name, email address (if released)
- the user's own bookings (start time, end time, optional purpose)
- the user's own time entries (day, activity, hours, optional note)
- an audit log of bookings, time entries and logins, pruned after 12 months

No other personal data is collected, and the application sends no email and
makes no outbound network connections. Access is restricted to netIDs that the
lab administrator has explicitly added to an allowlist; an authenticated netID
that is not on that list is refused and cannot use the application.

## Questions for ICT

1. Do you require AuthnRequests and/or SAML messages to be **signed** by the SP?
   (We sign AuthnRequests by default and can also require signed messages.)
2. Is a **test or acceptance IdP** available that we can register against first?
3. Is there a **multi-factor** policy that will apply to this SP? We deliberately
   send no `RequestedAuthnContext`, so your own policy governs.
4. What is the expected **lead time**, and is there a form or ticket queue we
   should use instead of this document?
5. How should we notify you when the **SP certificate** is rotated?
