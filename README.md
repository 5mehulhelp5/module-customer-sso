# DmLab_CustomerSso

> Provider-agnostic single sign-on for the Magento 2 storefront (OIDC).

[![CI](https://github.com/dmlab-work/module-customer-sso/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-customer-sso/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-customer-sso/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-customer-sso) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-customer-sso)](https://packagist.org/packages/dmlab/module-customer-sso) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-customer-sso)](https://packagist.org/packages/dmlab/module-customer-sso) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-customer-sso/php)](https://packagist.org/packages/dmlab/module-customer-sso) [![License](https://img.shields.io/packagist/l/dmlab/module-customer-sso)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

Shoppers sign in with the account they already have at your identity provider (Okta via [customer-sso-okta](https://github.com/dmlab-work/module-customer-sso-okta), or any OpenID Connect provider such as Keycloak or Authentik) instead of creating another password.
The first sign-in creates the customer, existing customers are linked by verified email, and IdP groups can set the customer group (e.g. wholesale).

![Customer SSO settings in Magento: enable, identity provider, client ID, password login and account linking](.github/images/config.png)

## Install

Usually installed via a provider plugin, which pulls this module and `sso-core`:

```bash
composer require dmlab/module-customer-sso-okta
bin/magento module:enable DmLab_CustomerSsoOkta DmLab_CustomerSso DmLab_SsoCore
bin/magento setup:upgrade
```

The core alone works on its own with [Generic OIDC](#any-oidc-provider):

```bash
composer require dmlab/module-customer-sso
bin/magento module:enable DmLab_SsoCore DmLab_CustomerSso
bin/magento setup:upgrade
```

Register the module callback URL in your IdP: `https://<store-host>/customersso/sso/callback`. It must exactly match the storefront URL used at runtime.

## Requirements

- Magento 2.4.7+ · PHP 8.2–8.5
- A provider plugin (e.g. `dmlab/module-customer-sso-okta`) or any OIDC provider via Generic OIDC
- `dmlab/module-sso-core` **^0.3.0** (installed automatically)
- The store's callback URL registered at the IdP

## Hyvä

Works out of the box on Hyvä themes, including Hyvä CSP: the login-page button switches to a Tailwind template automatically, no layout edits.

Blocks to restyle (override in the theme under `DmLab_CustomerSso/templates/…`):

- `dmlab.customer.sso.button` — `DmLab_CustomerSso::login/sso-button-hyva.phtml`
- `dmlab.customer.sso.identities` — `DmLab_CustomerSso::account/identities.phtml` (plain HTML, no JS)

To point the button at another template via layout, use the theme's `hyva_customer_account_login.xml`; it is applied after `customer_account_login.xml`.

Checkout status:

- Luma checkout fallback (`hyva-themes/magento2-luma-checkout`) — button shown in the checkout sign-in step and popup.
- Hyvä minicart/header sign-in popup and Hyvä Checkout — no SSO button, needs a separate integration (not shipped); shoppers sign in on the login page.

Pairs with [customer-sso-okta](https://github.com/dmlab-work/module-customer-sso-okta) (Okta sign-in) and [customer-passkey](https://github.com/dmlab-work/module-customer-passkey) (passkey sign-in).

## Configuration

Admin → Stores → Configuration → **DMLab → Customer SSO → General** (config path `dmlab_customer_sso/general/*`; the **Generic OIDC** group is `dmlab_customer_sso/generic/*`). Settings are store-scoped, so SSO can be enabled and tuned per store view.

| Field | Path | Notes |
|---|---|---|
| Enable Customer SSO | `enabled` | Master switch; off by default. |
| Identity Provider | `active_provider` | Built-in **Generic OIDC** plus presets from installed provider plugins. |
| Client ID | `client_id` | OIDC client id from the IdP. |
| Client Secret | `client_secret` | Stored encrypted; sensitive, so `app:config:dump` leaves it out — set it per environment. |
| Keep Password Login | `allow_password_login` | On (default): native email/password login stays available alongside SSO. Off: once SSO is live, login is SSO-only — the form is hidden and password login is refused server-side (login form, checkout popup, REST and GraphQL customer tokens). |
| Account Linking by Email | `auto_link_policy` | `require_verification` (default) or `disabled`. See below. |
| Group to Customer-Group Map | `group_customer_group_map` | IdP group → customer-group rules. See below. |
| Sign Out of IdP on Logout | `idp_logout` | Off by default. See below. |
| Issuer URL | `generic/issuer_url` | Generic OIDC only, required. Issuer or full discovery URL; https only (http for localhost). |
| Display Name | `generic/display_name` | Generic OIDC only. Button and My Account label; default `SSO`. |
| Scopes | `generic/scopes` | Generic OIDC only. Space-separated; default `openid profile email`, `openid` always sent. |
| Groups Claim | `generic/groups_claim` | Generic OIDC only. ID token claim with groups; default `groups`, empty = no group mapping (customer groups untouched). |
| Extra Authorization Parameters | `generic/extra_auth_params` | Generic OIDC only. One `key=value` per line, e.g. `prompt=select_account`. |

The "Sign in with SSO" button appears on the customer login page and in the Luma checkout login popups (authentication popup and the checkout authentication step; for Hyvä see [Hyvä](#hyvä)) when the module is enabled and a provider is selected; it uses the identity provider's branding (Generic OIDC shows its display name, no icon). Popup sign-in returns the shopper to checkout. Custom placements can link to `customersso/sso/start?referer=<base64 URL>` to return to that page; a URL off the store's own origin is ignored. Setting *Keep Password Login* to *No* hides the native form and refuses password login once SSO is live. As a break-glass, password login stays available on an unconfigured or broken install (no active provider, no Client ID, or an invalid issuer such as a blank Generic OIDC Issuer URL), so a mis-set toggle can never lock shoppers out.

## Any OIDC provider

Pick **Identity Provider = Generic OIDC** and fill the **Generic OIDC** group: Issuer URL, Display Name, Scopes, Groups Claim, Extra Authorization Parameters; Client ID and Secret stay under General. Issuer URL, Scopes and Extra Authorization Parameters are validated on save.

At the IdP, create a confidential client with redirect URI `https://<store-host>/customersso/sso/callback` and, only with *Sign Out of IdP on Logout*, post-logout URI `https://<store-host>/customer/account/logoutSuccess/`. A customer is created or adopted only when the ID token says `email_verified: true`.

### Keycloak

1. Manage realms → Create realm → Realm name `shop` → Create.
2. Clients → Create client → Client type *OpenID Connect*, Client ID `magento` → Next → *Client authentication* On, *Standard flow* on → Next → *Valid redirect URIs* = callback, *Valid post logout redirect URIs* = logout URI → Save. Copy the secret from the Credentials tab.
3. Clients → `magento` → Client scopes → `magento-dedicated` → Configure a new mapper (or Add mapper → By configuration) → *Group Membership*: Name `groups`, Token Claim Name `groups`, *Full group path* Off, *Add to ID token* On → Save. With *Full group path* On the values are `/wholesale` and the map lines must use that form.
4. Groups → Create group `wholesale`. Users → user → *Email verified* On; Groups tab → Join Group.
5. Magento: Issuer URL `https://<keycloak-host>/realms/shop`, Client ID/Secret, Display Name `Keycloak`, Groups Claim `groups`, map `wholesale=<customer_group_id>`.

### Authentik

1. Applications → Applications → New Application: Name `Magento`, Slug `magento` → provider *OAuth2/OpenID Connect* → Client type *Confidential*; Redirect URI *Strict* with the callback (type *Authorization* on 2026.5+; add the logout URI as a *Logout* entry if IdP logout is used); *Signing Key*: pick any — required, sso-core accepts only asymmetric signatures and without a key authentik signs with HS256. Note Client ID and Secret.
2. `email_verified`: authentik's default email mapping sends `false` (since 2025.10). Customization → Property Mappings → New Property Mapping → *Scope Mapping*, Scope name `email`, expression per authentik's "Verify email address" docs:
   ```python
   return {"email": request.user.email, "email_verified": True}
   ```
   On the provider, Advanced protocol settings → Scopes: replace the default `email` mapping with it (never both).
   This asserts every address as verified, so use it only when authentik verifies them (an *Email* stage in the enrollment and email-change flows, no self-service email edits); otherwise anyone can claim an existing customer by their email. If it does not, set *Account Linking by Email* to `disabled`.
3. Groups: the default `profile` mapping already sends `groups` (group names). Directory → Groups → create `wholesale` and add users.
4. Magento: Issuer URL `https://<authentik-host>/application/o/magento/` (per-provider issuer mode, the default; the trailing slash is fine), Groups Claim `groups`, map `wholesale=<customer_group_id>`.

## Account linking

An SSO identity is matched to a customer in this order:

1. **By IdP identity** — issuer (`iss`) + subject (`sub`) stored in `dmlab_customer_sso_subject` per provider. Signs in regardless of email verification. A link created before issuers were stored is bound to the issuer of its next matching login.
2. **By email**, governed by `auto_link_policy`:
   - `require_verification` (default) — adopt the existing customer only when the IdP marks the email verified (`email_verified`) and Magento has proven the account owns the address: an account with a password is adopted only after it confirmed its email (`customer/create_account/confirm` on), never while confirmation is pending or off, so a pre-registered account cannot capture the IdP user. Password-less accounts (e.g. admin-created) are adopted. Legacy `auto` resolves to this.
   - `disabled` — never link by email.

   Otherwise the login is refused and the shopper is told to sign in with their password and link from My Account — keep *Keep Password Login* on if existing customers may need this path.
3. **JIT create** — no match → a new customer is created only when the IdP marks the email verified; an unverified email is refused and nothing is created.

**Explicit linking** — a signed-in customer links or unlinks an identity under **My Account → Single Sign-On** (`customersso/account`). The session already proves ownership, so the verified flag is not checked; the link is bound to the session customer. A customer holds at most one identity per provider. An identity belongs to one customer — one per website when customer accounts are shared per website (`customer/account_share/scope`).

## Group → customer-group mapping

`group_customer_group_map` takes one rule per line as `idp_group=customer_group_id`, where `customer_group_id` is a Magento `customer_group` id. Lines are in priority order: a customer holds one group, so the first line matching any of the identity's groups wins, and a duplicate group keeps its first line. Blank lines and `#` comments are ignored.

```
# IdP group          Customer group id
wholesale=2
vip=3
```

With no rules configured this is inert: customer groups are never touched, so a store using SSO only for login keeps its manually assigned groups. Once a map exists, groups are resolved on JIT create and refreshed on every login, so IdP group changes take effect at next sign-in. An identity whose groups match no rule reverts to the store's default customer group (`GroupManagementInterface::getDefaultGroup`) — customers always hold a valid group. A rule pointing at a non-existent group id is skipped, leaving the current group unchanged. When the IdP marks the groups claim as distributed (groups overage, e.g. Entra `_claim_names`), the token's groups are incomplete and the current group is left unchanged.

## IdP logout

With **Sign Out of IdP on Logout** on, a shopper who signed in with SSO is also signed out of the IdP (OIDC RP-Initiated Logout 1.0): after the local logout the browser goes to the IdP's `end_session_endpoint` with `id_token_hint` (the login's ID token, kept in the customer session), `client_id` and `post_logout_redirect_uri`, then returns to the logout success page. This ends the shopper's IdP session for every app using it.

Register `https://<store-host>/customer/account/logoutSuccess/` (each store base URL) as a post-logout redirect URI at the IdP. If the IdP advertises no `end_session_endpoint`, discovery fails, the store's active provider changed since login, or the shopper signed in with a password, logout stays local.

## Architecture

This module is the storefront-login capability core: it signs shoppers in over OIDC via `sso-core` but never references a concrete IdP. A provider plugin (e.g. `customer-sso-okta`) registers a preset that binds it to an identity provider. The built-in Generic OIDC preset (from sso-core) covers standard IdPs from config alone; plugins stay the branded path.

1. Shopper clicks "Sign in with SSO" → `customersso/sso/start` builds the OIDC auth URL (state + nonce + PKCE) via sso-core and the active preset, persists the state and a `login` intent (optional own-origin return URL) in the customer session (valid 10 minutes, at most 10 pending per session), then redirects to the IdP. Linking starts the same way from `customersso/account/link` (POST, signed-in only) with a `link` intent.
2. IdP redirects back to `customersso/sso/callback`, which validates `state` and the RFC 9207 `iss` response parameter, exchanges the code, and normalizes claims into an `Identity` via sso-core. Failures return with a message to the login page, or to My Account → Single Sign-On for a `link` intent.
3. `login` (refused when a customer is already signed in): the customer is matched (issuer + `sub` → verified email under policy → verified JIT create), the group is resolved, the session is established and `mage-cache-sessid` is reset so private content reloads; redirect to the return URL or My Account.
4. `link`: the identity is linked to the session customer; redirect to My Account → Single Sign-On.

## License

[OSL-3.0](LICENSE) © DMLab. Commercial licensing and support: <https://dmlab.work>.
