# ARCHITECTURE

## Стек

| Шар | Вибір | Чому |
|---|---|---|
| Backend | Laravel 12 (PHP 8.2+), REST API | Вимога ТЗ; актуальний стабільний мажор (11-та гілка вже без bugfix-підтримки) |
| Auth | Laravel Sanctum, Bearer-токени | Мінімальний штатний спосіб «гравець з токена» |
| БД | SQLite (default) | Нульовий setup для перевіряючого; транзакції підтримуються. Код пишеться DB-агностично (Eloquent + міграції), перемикання на MySQL — лише `.env` |
| Frontend | Vue 3 (Composition API) + Vite + axios | Вимога ТЗ; Vite вбудований у Laravel |
| Тести | Pest (feature) | Стислий синтаксис; при `create-project` ставиться окремим кроком (`pestphp/pest-plugin-laravel`) |

Один репозиторій: корінь = Laravel-проєкт, Vue живе в `resources/js`, документація в `docs/`.

## Схема БД

```
users                          promo_codes
├─ id                          ├─ id
├─ name                        ├─ code            varchar(12), unique, UPPERCASE
├─ email          unique       ├─ amount_cents    unsigned bigint
├─ password                    ├─ expires_at      timestamp, nullable (null = безстроковий)
├─ balance_cents  unsigned     └─ timestamps
│                 bigint, default 0
└─ timestamps

promo_claims                             wallet_transactions
├─ id                                    ├─ id
├─ user_id        FK → users             ├─ user_id             FK → users
├─ promo_code_id  FK → promo_codes,      ├─ amount_cents        SIGNED bigint (+нарахування / −списання)
│                 NULLABLE (див. нижче)  ├─ type                enum: promo_claim | promo_revoke
├─ code_entered   varchar(12)            ├─ promo_claim_id      FK → promo_claims
├─ amount_cents   unsigned, nullable     ├─ balance_after_cents unsigned bigint
├─ status         enum: applied |        └─ created_at
│                 revoked | rejected
├─ reject_reason  enum, nullable:
│                 not_found | expired | already_used | cooldown
├─ revoked_at     timestamp, nullable
├─ timestamps
└─ UNIQUE (user_id, promo_code_id)   ← другий рубіж проти подвійного claim
```

**Ключовий прийом:** `promo_claims.promo_code_id` заповнюється **тільки** для «споживаючих» записів (`applied`, `revoked`). Для `rejected` він завжди `NULL`, а введений код зберігається у `code_entered`. Унікальні індекси в MySQL і SQLite ігнорують NULL → простий `UNIQUE (user_id, promo_code_id)` дає рівно те, що треба: скільки завгодно rejected-спроб, але не більше одного applied/revoked нарахування на пару «гравець × код». Жодних partial-індексів (яких немає в MySQL) не потрібно.

`wallet_transactions` — append-only ledger: кожна зміна балансу лишає рядок з підсумковим балансом. Інваріант для звірки: `users.balance_cents == SUM(wallet_transactions.amount_cents)` по гравцю.

Про невід'ємність балансу: основний інваріант — guard у сервісі + тести. `unsigned` Laravel на SQLite не діє (компілюється у звичайний INTEGER), а `ALTER TABLE ... ADD CONSTRAINT` SQLite не підтримує взагалі, тому raw CHECK `balance_cents >= 0` додається в міграції лише під MySQL (driver-умовно); на SQLite захист повністю програмний.

## Стратегія конкурентності (найважливіша частина)

Обидві грошові операції — `DB::transaction` + песимістичне блокування. **Правило порядку локів (єдине для всіх операцій): спершу рядок гравця, потім рядки `promo_claims`** — різний порядок у claim і revoke дав би класичний deadlock під MySQL (T1 тримає user і чекає claim, T2 тримає claim і чекає user → InnoDB error 1213).

```
claim:                                     revoke:
  BEGIN                                      BEGIN
  SELECT user FOR UPDATE       ← лок №1      SELECT user FOR UPDATE       ← лок №1
  SELECT claim … FOR UPDATE    ← locking     SELECT claim WHERE id AND user_id
    read: перевірка applied|revoked            FOR UPDATE                 ← лок №2
  INSERT claim (унікальний індекс —          if status not applied → 409 / abort
    страховка від гонки)                     UPDATE claim SET status='revoked'
  UPDATE user balance                          WHERE id=? AND status='applied'
  INSERT ledger                                (0 affected rows → 409)
  COMMIT                                     UPDATE user balance −amount
                                             INSERT ledger (−amount)
                                             COMMIT
```

Три рубежі проти подвійного нарахування:
1. **Лок рядка гравця** серіалізує грошові транзакції одного гравця. Важливо: перевірка «вже використаний» робиться **locking read'ом** (`lockForUpdate`) — звичайний SELECT під InnoDB REPEATABLE READ читає consistent snapshot транзакції і не побачить claim, закомічений паралельною транзакцією, поки ця чекала лок; locking read завжди читає останню закомічену версію.
2. **Унікальний індекс** (user_id, promo_code_id) — фінальна гарантія: навіть якщо лок обійдено (інший шлях коду, баг, особливості движка), другий INSERT впаде; `QueryException` перехоплюється і мапиться на 409 `PROMO_ALREADY_USED`.
3. **Rate limit** на ендпоінті — гасить перебір і флуд.

**Кулдаун «раз на 24 години» (D7 у [REQUIREMENTS.md](REQUIREMENTS.md)).** Перевірка живе всередині тієї ж транзакції claim, **після** лока рядка гравця і перевірки «цей код уже використаний»: locking read по `promo_claims` (`user_id = ? AND promo_code_id IS NOT NULL AND created_at > now() − 24h`) — звичайний SELECT знову не годиться через снапшот REPEATABLE READ. `promo_code_id IS NOT NULL` відсікає rejected-спроби: вони кулдаун не запускають. На відміну від «один код на гравця», це правило **неможливо виразити UNIQUE-індексом** (вікно ковзне), тож окремого DB-рубежа немає: фінальна гарантія — серіалізація всіх claim-ів гравця через лок його рядка `users` (рубіж №1). Це свідомий компроміс, і він безпечний рівно настільки, наскільки всі шляхи нарахування проходять через `PromoService::claim` — інших шляхів у системі немає. Довжина вікна — `config/promo.php` → `cooldown_hours` (env `PROMO_COOLDOWN_HOURS`, дефолт 24).

Проти подвійного списання — два рубежі: лок рядка claim + перевірка статусу всередині транзакції, і **умовний UPDATE** (`WHERE id=? AND status='applied'`) з перевіркою affected rows — СУБД-незалежна гарантія: 0 рядків означає, що статус уже змінено конкурентом → 409, кошти не списуються вдруге.

**Примітка про SQLite:** Laravel компілює `lockForUpdate()` у no-op для SQLite. SQLite серіалізує лише запис на рівні файла: подвійного нарахування/списання не станеться, але другий конкурентний писатель може отримати `SQLITE_BUSY` («database is locked») — без обробки це 500, а не контрактний 409. Тому контрактну відповідь гарантують СУБД-незалежні рубежі: для claim — унікальний індекс (перехоплений `QueryException` → 409), для revoke — умовний UPDATE. У демо-режимі (`php artisan serve`, один процес) конкуренція не виникає; на MySQL той самий код дає справжні row-локи і детермінований 409.

**Ретрай транзакцій (`attempts: 3`):** locking read на неіснуючій парі (user, promo) бере gap-лок на унікальному індексі; gap-локи двох різних гравців сумісні, а їхні наступні INSERT-и конфліктують з чужим gap-локом — можливий deadlock (InnoDB error 1213) навіть між різними гравцями. `DB::transaction(..., attempts: 3)` змушує Laravel прозоро ретраїти deadlock-жертву (і `SQLITE_BUSY` на SQLite); доменні винятки не ретраяться.

**Політика токенів:** Sanctum-токени живуть добу (`expiration` у config/sanctum.php), логін замінює попередній токен `spa` (не накопичуються), `POST /api/auth/logout` відкликає токен на сервері — «Вийти» робить викрадений токен марним, а не лише чистить localStorage.

## Структура backend-коду

```
app/
├─ Http/
│  ├─ Controllers/Api/
│  │  ├─ AuthController.php        login, me
│  │  └─ PromoController.php       claim, history, revoke — тонкі, вся логіка в сервісі
│  ├─ Requests/ClaimPromoRequest.php   валідація формату коду (422)
│  └─ Resources/PromoClaimResource.php
├─ Services/PromoService.php       claim(User, string): ClaimResult
│                                  revoke(User, int): RevokeResult
├─ Exceptions/PromoException.php   базовий доменний виняток → {message, error_code}, мапиться
│                                  на HTTP-код у render()
└─ Models/  User, PromoCode, PromoClaim, WalletTransaction
```

Принципи:
- Контролер: авторизація + виклик сервісу + Resource. Нуль бізнес-логіки.
- Сервіс кидає типізовані доменні винятки (`PromoNotFound`, `PromoExpired`, `PromoAlreadyUsed`, `PromoCooldown`, `ClaimNotFound`, `ClaimAlreadyRevoked`, `ClaimNotRevocable`) — один обробник перетворює їх на JSON з правильним HTTP-кодом; виняток може додати власні машинні поля до відповіді (`PromoCooldown` → `next_claim_available_at`).
- Мутації балансу — тільки через сервіс, тільки в транзакції, тільки з ledger-рядком.

## API

| Метод | Шлях | Auth | Призначення |
|---|---|---|---|
| POST | `/api/auth/login` | — | email+password → `{token, user}` (для демо; гравець сідиться) |
| GET | `/api/me` | ✔ | Поточний гравець + `balance_cents` (для шапки фронтенда) |
| POST | `/api/promo/claim` | ✔, throttle | Тікет 1 |
| GET | `/api/promo/history` | ✔ | Тікет 1, пагінація + фільтр |
| PATCH | `/api/promo/{claimId}/revoke` | ✔ | Тікет 2 |

Контракти запитів/відповідей і помилок — у [REQUIREMENTS.md](REQUIREMENTS.md).

## Структура frontend

```
resources/js/
├─ app.js                бутстрап Vue
├─ api.js                axios instance: baseURL /api, Bearer з localStorage,
│                        interceptor 401 → розлогін
├─ App.vue               layout: шапка з балансом, LoginForm або робочий екран
└─ components/
   ├─ LoginForm.vue      мінімальний вхід (демо-креденшли підказані на формі)
   ├─ PromoClaimForm.vue стани idle/loading/success/error
   └─ PromoHistory.vue   список + фільтр-таби + пагінація + кнопка «Скасувати»
                         з confirm та per-row loading
```

Стан — локальний у компонентах + прості події (`claimed` → історія перезавантажується, баланс оновлюється). Без Pinia/Vuex — для двох екранів це шум.

## Сідери (для тестів і демо-відео)

| Що | Значення |
|---|---|
| Гравець | `player@demo.test` / `password`, баланс 0 |
| Другий гравець | `other@demo.test` / `password` (для тестів ізоляції) |
| `WELCOME50` | 50.00, безстроковий |
| `SUMMER100` | 100.00, діє до +30 днів |
| `EXPIRED25` | 25.00, прострочений (−1 день) — для демо помилки |

## Тестування

Pest feature-тести проти матриці з [REQUIREMENTS.md](REQUIREMENTS.md): `ClaimPromoTest`, `PromoHistoryTest`, `RevokePromoTest`. Гонки покриваються детерміновано: тест на спрацювання унікального індексу (другий INSERT в обхід перевірки) і тест «claim → revoke → claim → 409». Ledger звіряється в тестах: після кожної операції `SUM(ledger) == balance`.
