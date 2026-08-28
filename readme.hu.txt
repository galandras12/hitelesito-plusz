=== Hitelesítő+ ===
Contributors: galandras12
Tags: two factor, 2fa, totp, passkey, webauthn, security, brute force
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Teljes körű, modern kétfaktoros hitelesítés WordPresshez: TOTP, e-mail kód, Passkey (WebAuthn) és biztonsági mentési kódok, szerepkör alapú kötelezővé tétellel.

== Description ==

A **Hitelesítő+** négyféle kétfaktoros hitelesítési módszert kínál egyetlen pluginban:

* **TOTP** - Google Authenticator, Microsoft Authenticator és más kompatibilis alkalmazások, QR kóddal (nagy, jól látható méretben) és kézi kód megadási lehetőséggel.
* **E-mail kód** - egyszer használatos kód a felhasználó e-mail címére, testre szabható HTML sablonnal és állítható érvényességi idővel (15 perctől 12 óráig).
* **Passkey (WebAuthn)** - ujjlenyomat, arcfelismerés, PIN vagy biztonsági kulcs, elmenthető eszköznévvel.
* **Biztonsági mentési kódok** - 10 db egyszer használatos kód, TXT fájlként letölthető, vészhelyzeti belépéshez.

**Fő jellemzők:**

* Szerepkör alapú mátrix: minden hitelesítési módszer szerepkörönként állítható **kötelező / opcionális / rejtett** állapotba.
* A bejelentkezés a megszokott `wp-login.php`-n történik, a kétfaktoros ellenőrzés egy különálló, nem wp-login.php alapú, saját, reszponzív felületre irányítva zajlik - ezzel tehermentesítve a `wp-login.php`-t.
* Opcionálisan bekapcsolható brute force védelem (IP + felhasználónév alapú ideiglenes zárolás).
* Admin e-mail riasztás, ha egy felhasználó egymás után többször (alapértelmezetten 5x) elrontja a kétfaktoros hitelesítést.
* `[hitelesito_plusz_beallitas]` shortcode bejegyzésbe, oldalba vagy widgetbe illesztve - a felhasználó saját maga kezelheti a hitelesítőit.
* Admin felületen felhasználónként letiltható bármelyik hitelesítő módszer (pl. ha valaki elveszíti a hozzáférését).
* Modern, minimalista, fehér, teljesen reszponzív admin és frontend felület.

== Installation ==

1. Töltsd fel a `hitelesito-plusz` mappát a `/wp-content/plugins/` könyvtárba, vagy telepítsd ZIP-ként a WordPress admin felületén.
2. Aktiváld a plugint.
3. Az admin menüben nyisd meg a **Hitelesítő+** menüpontot, és állítsd be a szerepkör-mátrixot, az e-mail sablont és a biztonsági beállításokat.
4. Illeszd be a `[hitelesito_plusz_beallitas]` shortcode-ot egy oldalba, hogy a felhasználók beállíthassák saját hitelesítőiket.

== Frequently Asked Questions ==

= Terheli a wp-login.php-t a plugin? =

Nem. A jelszavas bejelentkezés a megszokott módon, a `wp-login.php`-n történik. Sikeres jelszavas belépés után, ha a felhasználónak kétfaktoros hitelesítésre van szüksége, egy különálló, saját oldalra irányítjuk át.

= Mi történik, ha valaki elveszíti a hozzáférését az összes hitelesítőjéhez? =

Az admin a felhasználó profilszerkesztő oldalán, vagy a Hitelesítő+ admin felületen keresztül letilthatja az adott felhasználó bármelyik hitelesítő módszerét.

== Changelog ==

= 1.1 =
* Javítva: a biztonsági mentési kódok TXT letöltése 403 hibát adhatott bizonyos gyorsítótárazó pluginok/szerverek mellett - a letöltés mostantól teljesen a böngészőben (szerver-kérés nélkül) történik.
* Javítva: gyorsítótárazó pluginok/szerverek elavult biztonsági nonce-ot szolgálhattak ki a bejelentkezés utáni ellenőrző oldalon, ami miatt minden TOTP-, e-mail- és Passkey-ellenőrzés "Hiba történt, próbáld újra" üzenettel meghiúsult. A frontend mostantól minden oldalbetöltéskor és minden sikertelen próbálkozás után automatikusan friss, soha nem gyorsítótárazott nonce-ot kér.
* A bejelentkezés utáni ellenőrző és a saját beállító oldal explicit no-cache HTTP fejléceket küld.

= 1.0 =
* Első kiadás: TOTP, e-mail kód, Passkey (WebAuthn), biztonsági mentési kódok, szerepkör-mátrix, brute force védelem, admin riasztás, shortcode.
