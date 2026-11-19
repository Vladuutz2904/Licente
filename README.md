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

## 2. Adauga baza de date KV

1. In dashboard-ul Vercel, intra in proiect -> tab **Storage** -> **Create
   Database** -> alege **KV** (Upstash Redis, free tier).
2. Conecteaza-o la proiect ("Connect to Project"). Vercel adauga automat
   variabilele de mediu necesare (`KV_REST_API_URL`, `KV_REST_API_TOKEN`
   etc.) - nu trebuie sa le copiezi tu manual.

## 3. Seteaza parola de admin

In proiect -> **Settings** -> **Environment Variables**, adauga:

```
ADMIN_SECRET = o-parola-lunga-si-random-pe-care-nu-o-stie-nimeni
```

Apoi fa un redeploy (Settings -> Deployments -> ... -> Redeploy), ca sa
preia noua variabila.

## 4. Testeaza

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

## 5. Integreaza in craftelite

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
