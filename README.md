# Server de licente pentru craftelite (Vercel)

Ce face: tine o lista de licente (domeniu -> data expirarii) intr-o baza de
date gratuita (Vercel KV, bazata pe Redis). Panoul PHP intreaba periodic
"e licenta mea valida?" si se blocheaza (fara sa stearga nimic) daca nu e.

## 1. Creeaza proiectul pe Vercel

1. Baga folderul `license-vercel/` (fara `panel/`, acela ramane in codul
   panoului) intr-un repo pe GitHub, sau instaleaza Vercel CLI:
   ```
   npm i -g vercel
   cd license-vercel
   vercel
   ```
2. Daca folosesti GitHub: mergi pe vercel.com -> "Add New Project" ->
   importa repo-ul.

## 2. Adauga baza de date (Upstash Redis, prin Marketplace)

Vercel KV (vechiul produs) nu mai exista ca atare - a fost inlocuit cu
integrarea **Upstash Redis** din Marketplace. E tot un key-value store
gratuit, doar ca se adauga altfel:

**Varianta din dashboard:**
1. In proiectul tau pe vercel.com -> tab **Storage** -> **Browse Marketplace**
   (sau **Create Database**).
2. Cauta **Upstash** -> alege **Upstash Redis** -> plan **Free**.
3. Apasa **Connect to Project** si selecteaza proiectul `license-vercel`
   si mediile (Production + Development).
4. Vercel adauga automat variabilele `KV_REST_API_URL` si
   `KV_REST_API_TOKEN` in Environment Variables - nu le copiezi tu manual.

**Varianta din CLI** (daca ai `vercel` instalat si ai facut `vercel link`
in folderul proiectului):
```bash
vercel integration add upstash
```
si urmezi promptul ca sa creezi/conectezi baza de date.

5. Dupa ce ai conectat-o, fa un redeploy (Deployments -> ... -> Redeploy)
   ca proiectul sa preia noile variabile de mediu.

## 3. Seteaza parola de admin

In proiect -> **Settings** -> **Environment Variables**, adauga:

```
ADMIN_SECRET = o-parola-lunga-si-random-pe-care-nu-o-stie-nimeni
ADMIN_USER   = Vladuutz
ADMIN_PASS   = o-parola-noua-pe-care-o-alegi-tu
```

**Important:** codul are valori de rezerva (`Vladuutz` / parola din discutie)
ca sa mearga din prima daca uiti variabilele astea, dar daca proiectul
ajunge vreodata intr-un repo public pe GitHub, parola de rezerva devine
vizibila oricui. Seteaza `ADMIN_USER` si `ADMIN_PASS` aici, cu o parola
noua, si ignora-le pe cele din cod.

Apoi fa un redeploy (Deployments -> ... -> Redeploy), ca sa preia noile
variabile.

## 4. Panou vizual de administrare (genereaza/revoca licente din browser)

Dupa deploy, mergi direct la:

```
https://NUME-PROIECT.vercel.app/
```

Te loghezi cu `ADMIN_USER` / `ADMIN_PASS` (cele din Environment Variables,
sau cele de rezerva daca nu le-ai setat inca). Panoul are:

- **Dashboard** - cate licente ai in total, cate sunt active, expirate
  sau revocate, plus ultimele 5 modificate.
- **Genereaza cheie** - completezi domeniul clientului, numarul de zile
  si o nota optionala. Primesti o cheie gen `CFTE-X7K2-9PQR-4MNB`, cu
  buton de copiere, pe care o dai clientului ca referinta.
- **Licente** - tabel cu toate licentele, cautare dupa domeniu sau nota,
  si pentru fiecare: copiaza cheia, **revoca**, **reactiveaza** sau
  **sterge definitiv**.

Sesiunea de login tine 12 ore (cookie securizat), dupa care trebuie sa te
loghezi din nou.

**Despre cheia afisata:** validarea reala (facuta de `license_check.php`
din panoul craftelite) se face dupa domeniu, nu dupa cheie - cheia e
doar un cod de referinta frumos, pe care il dai clientului. Daca vrei
ca si cheia sa fie verificata explicit de client (ex. o introduce manual
undeva), spune-mi si adaug un endpoint separat pentru asta.

## 5. Testeaza din linia de comanda (optional)

Daca preferi `curl` in loc de panoul vizual, endpoint-urile vechi
(`/api/admin/license` si `/api/admin/revoke`) tot functioneaza, dar cu
`x-admin-secret` in loc de login:

Dupa deploy, ai un URL gen `https://craftelite-licenses.vercel.app`.

**Creezi o licenta** (30 de zile) pentru un client:
```bash
curl -X POST https://craftelite-licenses.vercel.app/api/admin/license \
  -H "Content-Type: application/json" \
  -H "x-admin-secret: o-parola-lunga-si-random-pe-care-nu-o-stie-nimeni" \
  -d '{"domain": "panoul-clientului.com", "days": 30, "note": "plata noiembrie"}'
```

**Verifici** (asta e endpoint-ul public, il foloseste panoul):
```bash
curl "https://craftelite-licenses.vercel.app/api/check?domain=panoul-clientului.com"
```

**Revoci** o licenta (ex. client nu a platit):
```bash
curl -X POST https://craftelite-licenses.vercel.app/api/admin/revoke \
  -H "Content-Type: application/json" \
  -H "x-admin-secret: o-parola-lunga-si-random-pe-care-nu-o-stie-nimeni" \
  -d '{"domain": "panoul-clientului.com"}'
```

## 6. Integreaza in craftelite

1. Copiaza `panel/license_check.php` in radacina panoului (langa
   `index.php` sau fisierul principal de bootstrap).
2. Deschide-l si schimba linia:
   ```php
   define('LICENSE_API_URL', 'https://NUME-PROIECT.vercel.app/api/check');
   ```
   cu URL-ul tau real de Vercel.
3. In fisierul principal al panoului (primul lucru care ruleaza la fiecare
   request), adauga:
   ```php
   require_once __DIR__ . '/license_check.php';
   ```

Asta e tot. La fiecare request, panoul verifica (din cache, o data la 6 ore)
daca licenta e valida. Daca nu e, sau daca tu o revoci manual, clientul
vede o pagina de "acces suspendat" - dar fisierele si baza lui de date
raman intacte, deci cand plateste, ii dai licenta inapoi si merge mai
departe normal.

## De retinut

- Un client priceput la cod ar putea sa gaseasca si sa stearga
  `license_check.php` din fisierele lui. Pentru protectie mai serioasa,
  integreaza verificarea direct in codul de bootstrap/core al panoului,
  nu intr-un fisier separat usor de identificat.
- Pastreaza `ADMIN_SECRET` doar la tine - oricine il are poate crea sau
  revoca licente.
