# VladuutzPanel — server de licente pentru craftelite (Vercel)

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

## 4. Panou vizual de administrare

Dupa deploy, mergi pe `https://NUME-PROIECT.vercel.app/` si te loghezi cu `ADMIN_USER` / `ADMIN_PASS`.

- **Genereaza cheie** - alegi **Temporara (zile)** sau **Permanenta**, optional domeniul si o nota.
  Primesti o cheie gen `CFTE-X7K2-9PQR-4MNB`. Daca lasi domeniul gol, cheia se leaga singura
  de primul panou care o foloseste.
- **Licente** - pentru fiecare cheie: **dezactiveaza / activeaza**, **editeaza** (prelungesti cu N zile,
  o faci permanenta, schimbi domeniul / nota) si **sterge definitiv**. Vezi si cand a verificat panoul ultima oara.

## 5. Cum folosesc panourile licenta

1. Admin genereaza cheia din panou.
2. In panou (ex. Krashout) intri pe site -> esti trimis la `licenta.php` -> lipesti cheia -> activ.
3. Panoul intreaba serverul (`/api/check?key=...&domain=...`) din ~45 in 45 de secunde. Orice faci cu licenta
   (dezactivezi, stergi, expira, prelungesti, o faci permanenta) se vede automat in panou in cel mult ~1 minut.
   Fara licenta valida panoul se blocheaza, dar nu se sterge nimic din fisiere sau baza de date.

Pentru alt panou PHP (nu Krashout): copiaza `panel/license_check.php` in radacina lui si adauga primul lucru
in fisierul principal: `require_once __DIR__ . '/license_check.php';` (are pagina de activare inclusa).

## 6. Daca la creare apare o eroare

Acum eroarea reala apare in notificare. Cea mai frecventa: **baza de date neconectata** -> Vercel ->
Storage -> Upstash Redis -> Connect to Project, apoi **Redeploy**.

## De retinut

- Schimba `ADMIN_USER` / `ADMIN_PASS` / `ADMIN_SECRET` in Environment Variables (valorile de rezerva din cod
  sunt vizibile oricui vede codul).
- Licentele vechi (legate doar de domeniu) se migreaza automat la prima deschidere a listei si primesc cheie.
