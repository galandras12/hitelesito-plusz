=== Hitelesítő+ ===
Contributors: galandras12
Tags: two factor, 2fa, totp, passkey, webauthn, security, brute force
Requires at least: 5.8
Tested up to: 7.0
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

= 1.5 =
* Javítva/diagnosztizálva: a hibarészletek panel most már felismeri, ha egy biztonsági/tűzfal bővítmény (pl. Shield Security, Wordfence, Sucuri, vagy más bot-védelem) blokkolja vagy átirányítja az admin-ajax.php-ra küldött kérést a várt JSON válasz helyett egy teljes weboldalt küldve vissza - ilyenkor most konkrét, magyarázó üzenetet ad a "Hibás/lejárt kód" helyett.
* Minden AJAX kérés mostantól elküldi a szabványos `X-Requested-With: XMLHttpRequest` fejlécet, amit a legtöbb biztonsági bővítmény a valódi böngésző-eredetű AJAX kérések felismerésére használ - ez sok esetben önmagában megszünteti a téves blokkolást.

= 1.4 =
* Javítva: időzóna-keveredés miatt egy még érvényes (pl. 8 órás lejáratú) e-mailes kód is tévesen "lejártnak" tűnhetett, ha a szerver saját rendszerideje (általában UTC) eltért a WordPress-ben beállított helyi időtől (pl. UTC+2). Az összes időbélyeg (e-mail kód lejárata, brute force ablak, WebAuthn/Passkey kihívás frissessége, létrehozási/felhasználási időpontok) mostantól következetesen, kizárólag UTC-ben van tárolva és összehasonlítva, függetlenül a szerver vagy a WordPress időzóna-beállításától.
* Az e-mail kód ellenőrzésének hibaüzenete mostantól a konkrét okot is jelzi (nincs érvényes/fel nem használt kód ehhez a fiókhoz, vagy a megadott kód nem egyezik), ami a "Hiba részletei" panelben is látszik.
* A passkey-ek listájában megjelenő létrehozási dátum most a helyes (WordPress-ben beállított) helyi időben jelenik meg.

= 1.3 =
* Új: minden AJAX hiba (bejelentkezéskor és a saját beállító felületen egyaránt) mostantól egy kimásolható "Technikai hibarészletek" panelban is megjelenik (időpont, művelet, HTTP állapot, nyers szerverválasz, böngésző) - ezt a felhasználó egy kattintással kimásolhatja és elküldheti az adminisztrátornak pontos hibabejelentéshez.
* Új: mobil eszközön a TOTP beállításnál megjelenő QR kód érinthető hivatkozássá válik "Érintse meg a gyors párosításhoz" felirattal, ami megnyitja az eszközön regisztrált alapértelmezett hitelesítő alkalmazást a kézi QR-beolvasás megkerülésével.

= 1.2 =
* Javítva: ha csak az e-mail kódos hitelesítés volt bekapcsolva, a rendszer bejelentkezéskor egyáltalán nem kérte a kétfaktoros hitelesítést (átugrotta, mintha nem lenne beállítva 2FA).
* Javítva: a bejelentkezés utáni ellenőrző oldalon a TOTP-, e-mail- és Passkey-ellenőrzések bizonyos szerverkonfigurációk mellett mindig "Hiba történt, próbáld újra" üzenettel hiúsulhattak meg, konkrét ok kiírása nélkül. A pending (bejelentkezés közbeni) ellenőrző végpontok mostantól nem a beágyazott biztonsági nonce-ra támaszkodnak (ami gyorsítótárazás vagy egyéb szerverkörülmények miatt eltérhetett), hanem kizárólag a saját, egyszer használatos, titkos munkamenet-tokenre.
* Minden AJAX végpont most egy védőrétegen keresztül fut: PHP figyelmeztetések/hibák többé nem törhetik meg a JSON választ, és váratlan hiba esetén is konkrét (WP_DEBUG mellett részletes) hibaüzenet jelenik meg a felugró ablakban és a böngésző konzoljában, nem csak egy általános "Hiba történt" szöveg.

= 1.1 =
* Javítva: a biztonsági mentési kódok TXT letöltése 403 hibát adhatott bizonyos gyorsítótárazó pluginok/szerverek mellett - a letöltés mostantól teljesen a böngészőben (szerver-kérés nélkül) történik.
* Javítva: gyorsítótárazó pluginok/szerverek elavult biztonsági nonce-ot szolgálhattak ki a bejelentkezés utáni ellenőrző oldalon, ami miatt minden TOTP-, e-mail- és Passkey-ellenőrzés "Hiba történt, próbáld újra" üzenettel meghiúsult. A frontend mostantól minden oldalbetöltéskor és minden sikertelen próbálkozás után automatikusan friss, soha nem gyorsítótárazott nonce-ot kér.
* A bejelentkezés utáni ellenőrző és a saját beállító oldal explicit no-cache HTTP fejléceket küld.

= 1.0 =
* Első kiadás: TOTP, e-mail kód, Passkey (WebAuthn), biztonsági mentési kódok, szerepkör-mátrix, brute force védelem, admin riasztás, shortcode.