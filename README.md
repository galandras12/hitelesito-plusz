# Hitelesítő+ – Two-Factor Authentication (2FA) for WordPress

**Hitelesítő+** (Authenticator+) is a modern, secure, and flexible two-factor authentication (2FA) plugin for WordPress websites. It provides complete protection for user accounts using industry-standard protocols and security features.

---

## English Description

### Key Features & Supported Authentication Methods

The plugin offers 4 distinct authentication methods:

1. **TOTP (Authenticator App)**
   - Compatible with Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden, and any RFC 6238 compliant app.
   - Generates a clear QR code for quick scanning and supports manual secret key entry.
   - Displays a tap-to-open link on mobile devices for seamless app pairing.

2. **Email One-Time Passcode (Email 2FA)**
   - Sends a secure 6-digit passcode to the user's registered email address.
   - Customizable email subject and HTML email body template.
   - Configurable code expiration lifetime (from 15 minutes up to 12 hours).
   - Built-in rate-limiting throttle to prevent email spamming.

3. **Passkey / WebAuthn (FIDO2)**
   - Biometric authentication: fingerprint scanners (Touch ID / Windows Hello), facial recognition (Face ID), PIN code, or physical hardware security keys (YubiKey).
   - Dependency-free native PHP WebAuthn / COSE / CBOR implementation.
   - Device labeling and management directly in the user profile.

4. **Emergency Backup Codes**
   - Generates 10 single-use emergency backup codes.
   - Downloadable directly as a TXT file from the browser for secure offline storage.
   - Serves as a recovery fallback if a user loses access to their primary 2FA device or email.

### Core Architecture & Features

- **Isolated 2FA Verification Page:** Password authentication takes place on the standard `wp-login.php`. Upon successful password entry, users are redirected to a dedicated, responsive 2FA verification page (`/?h2f_action=verify`).
- **Role-Based Policy Matrix:** Administrators can configure policy states (**Required**, **Optional**, or **Hidden**) for each authentication method individually per WordPress user role (Administrator, Editor, Author, Subscriber, etc.).
- **Brute-Force Protection:** Built-in IP and username tracking with temporary lockouts covering both primary password login and secondary 2FA verification attempts (TOTP, Email, Backup codes, Passkeys).
- **Brute-Force Protection:** Built-in IP and username login attempt tracking with temporary lockouts to prevent automated brute-force attacks.
- **Admin Security Alerts:** Automated email notifications sent to site administrators upon repeated failed 2FA attempts on a user account.
- **Shortcode Support:** Embed the `[hitelesito_plusz]` shortcode on any page, post, or member dashboard to allow users to manage their 2FA credentials on the frontend.
- **Admin Management:** Administrators can disable individual 2FA methods for locked-out users directly from the user edit screen (`user-edit.php`).

### System Requirements

- **WordPress Version:** 5.8 or higher (Tested up to: 7.0.4)
- **PHP Version:** 8.0 or higher
- **Database:** MySQL 5.6+ / MariaDB 10.1+

### Installation

1. Upload the `hitelesito-plusz` folder to the `/wp-content/plugins/` directory (or install the ZIP file via Admin -> Plugins -> Add New).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the **Hitelesítő+** menu in the WordPress Admin Dashboard.
4. Configure role policy rules, email templates, and security options.
5. (Optional) Create a page with the `[hitelesito_plusz]` shortcode so users can manage their 2FA options.

---

## Magyar Leírás (Hungarian Description)

# Hitelesítő+ – Többfaktoros Hitelesítés (2FA) WordPresshez

**Hitelesítő+** egy modern, biztonságos és rugalmas kétfaktoros (2FA) hitelesítési bővítmény WordPress weboldalakhoz. Teljes körű védelmet nyújt a felhasználói fiókok számára, támogatva a legújabb biztonsági szabványokat és eszközöket.

### Főbb funkciók és támogatott hitelesítési módszerek

A bővítmény 4 különböző hitelesítési módszert kínál:

1. **TOTP (Hitelesítő alkalmazás)**
   - Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden és egyéb RFC 6238 kompatibilis alkalmazások támogatása.
   - Generált QR-kód a gyors párosításhoz, valamint kézi titkos kulcs megadási lehetőség.
   - Mobileszközökön közvetlenül érinthető hivatkozás az authenticator app azonnali megnyitásához.

2. **E-mailben küldött egyszer használatos kód (Email 2FA)**
   - Biztonságos 6-jegyű kód kiküldése a felhasználó regisztrált e-mail címére.
   - Testreszabható e-mail tárgy és HTML levélsablon.
   - Állítható érvényességi idő (15 perctől akár 12 óráig).
   - Beépített rate-limiting védelem az e-mail spammelés megelőzésére.

3. **Passkey / WebAuthn (FIDO2)**
   - Biometrikus azonosítás: ujjlenyomat-olvasó (Touch ID / Windows Hello), arcfelismerés (Face ID), PIN kód vagy fizikai biztonsági kulcsok (YubiKey).
   - Külső függőségektől mentes WebAuthn / COSE / CBOR megvalósítás.
   - Eszközök elnevezése és kezelése a felhasználói fiókban.

4. **Biztonsági mentési kódok (Backup codes)**
   - 10 darab egyszer használatos vészhelyzeti kód generálása.
   - TXT fájlként közvetlenül letölthető a böngészőből offline tárolásra.
   - Felhasználható, ha a felhasználó nem fér hozzá a telefonjához vagy e-mail fiókjához.

### Működés és architektúra

- **Különálló 2FA felület:** A jelszó megadása a megszokott `wp-login.php` oldalon történik. Sikeres jelszavas azonosítás után a bővítmény átirányítja a felhasználót egy saját, biztonságos, reszponzív kétfaktoros ellenőrző felületre (`/?h2f_action=verify`).
- **Szerepkör alapú szabályzat (Role Matrix):** Minden egyes felhasználói szerepkörhöz (pl. Administrator, Editor, Subscriber) külön beállítható az egyes hitelesítési módok állapota:
  - **Kötelező (Required):** A szerepkörbe tartozó felhasználóknak kötelező használniuk/beállítaniuk az adott módszert.
  - **Opcionális (Optional):** A felhasználó szabadon dönthet a használatáról.
  - **Rejtett (Hidden):** Az adott szerepkör számára nem érhető el a módszer.
- **Brute Force védelem:** IP-cím és felhasználónév alapú kísérletszámlálás és ideiglenes zárolás mind a jelszavas belépésnél, mind a 2FA-kódok (TOTP, E-mail, Biztonsági kódok, Passkey) ellenőrzésénél.
- **Brute Force védelem:** IP-cím és felhasználónév alapú kísérletszámlálás és ideiglenes zárolás sikertelen próbálkozások esetén.
- **Adminisztrátori riasztás:** Automatikus e-mail értesítés küldése az adminisztrátoroknak gyanús, ismételten sikertelen 2FA belépési kísérletek esetén.
- **Shortcode támogatás:** A `[hitelesito_plusz]` shortcode segítségével a kétfaktoros beállítási felület bármelyik WordPress oldalba, bejegyzésbe vagy tagi felületre beágyazható.
- **Adminisztrátori kezelés:** Az adminisztrátorok a felhasználói profil oldalon (`user-edit.php`) egy kattintással letilthatják a kizárt vagy hozzáférést vesztett felhasználók hitelesítőit.

### Rendszerkövetelmények

- **WordPress verzió:** 5.8 vagy újabb (Tesztelve: 7.0.4)
- **PHP verzió:** 8.0 vagy újabb
- **Adatbázis:** MySQL 5.6+ / MariaDB 10.1+

### Telepítés

1. Töltsd fel a `hitelesito-plusz` mappát a WordPress weboldal `/wp-content/plugins/` könyvtárába (vagy telepítsd a ZIP fájlt az Admin -> Bővítmények -> Új hozzáadása felületen).
2. Aktiváld a bővítményt.
3. Nyisd meg a **Hitelesítő+** menüpontot a WordPress adminisztrációs felületén.
4. Állítsd be a szerepkörök szabályzatát, az e-mail sablont és a kívánt biztonsági opciókat.
5. (Opcionális) Hozz létre egy oldalt a `[hitelesito_plusz]` shortcode beillesztésével, ahol a felhasználók kényelmesen beállíthatják saját kétfaktoros eszközeiket.

---

## License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) license.
