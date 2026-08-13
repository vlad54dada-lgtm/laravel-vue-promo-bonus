# Design

Візуальна система Promo Bonus. Джерело істини для токенів — `resources/css/app.css` (`@theme`).

## Theme

Темна, restrained: майже чорні нейтрали з холодним підтоном (hue 260) + один м'ятний акцент. Сцена: гравець відкриває кабінет увечері; продукти категорії (беттинг/казино) — темні за замовчуванням. Без казино-кітчу: жодного золота, неону, гласморфізму.

## Color tokens (OKLCH)

| Токен | Значення | Роль |
|---|---|---|
| `canvas` | `oklch(0.16 0.012 260)` | фон сторінки |
| `card` | `oklch(0.205 0.014 260)` | поверхня карток |
| `card-2` | `oklch(0.25 0.016 260)` | піднята поверхня (сегмент-контрол, chips, скелетони) |
| `line` / `line-soft` | `oklch(0.32/0.27 …)` | межі, роздільники |
| `ink` | `oklch(0.95 0.005 260)` | основний текст |
| `ink-soft` | `oklch(0.75 …)` | вторинний текст (≥8:1 на canvas) |
| `ink-faint` | `oklch(0.63 …)` | мета-текст, таймстемпи (≥5:1 на card) |
| `mint` / `mint-soft` / `mint-deep` / `mint-ink` | hue 162 | акцент: ТІЛЬКИ гроші-плюс і primary CTA |
| `danger` / `danger-strong` / `danger-deep` | hue 25 | деструктивне і відмови |
| `warn` | hue 85 | клієнтський hint формату |

Правило: акцент не буває декорацією. Mint = «гроші прийшли» + головна дія; danger = «гроші йдуть» + помилка.

## Typography

Одна сім'я — Instrument Sans (400/500/600/700), моно — системний стек для промокодів. Баланс у шапці: 2rem/700/tabular-nums — головна цифра екрана. Всі грошові значення — `tabular-nums`. Заголовки секцій: 1rem/600/tracking-tight.

## Motion

Токени: `--ease-out-strong: cubic-bezier(0.23,1,0.32,1)`, `--ease-in-out-strong: cubic-bezier(0.77,0,0.175,1)`.

| Що | Як | Тривалість |
|---|---|---|
| Press (усе натискне) | `.pressable` → scale(0.97) | 140ms ease-out-strong |
| Повідомлення success/error | `pop`: opacity + translateY(-4px) | enter 200 / leave 130ms |
| Модалка | сцим fade; панель scale 0.96 + translateY(8px) → 1 (ніколи від нуля) | enter 200 / leave 130ms |
| Екрани логін↔кабінет | кросфейд `screen`, boot-стан ПОЗА Transition | 200 / 130ms |
| Баланс | rAF count-up, easeOutCubic | 400ms |
| Спінер | `.spin-fast` | 600ms linear |

Правила: рух передає стан, не прикрашає; exit швидший за enter; жодних оркестрованих завантажень (скелетони замість хореографії); видимість контенту ніколи не гейтиться анімацією. `prefers-reduced-motion`: рух → 1ms/вимкнено, opacity-зміни лишаються.

## Perceived speed

Історія — SWR-кеш сторінок (`${status}|${page}`): перемикання фільтрів/сторінок показує кеш миттєво (5–10ms до оновленого DOM) і тихо ревалідовує у фоні; після першого завантаження решта фільтрів префетчиться. Скелетон — лише коли для ключа ще немає даних. Після claim/revoke кеш інвалідовується повністю.

## Iconography

Одна сім'я інлайн-SVG (Lucide-геометрія, stroke 2, round): ticket (бренд, інпут, порожній стан), check/x/undo (статуси в колах-підложках), chevrons (пагінація), log-out, check-circle/alert (повідомлення). Жодних emoji в ролі іконок.

## Components

- **Поверхні**: `.card-surface` — 1px бордер + ледь помітний вертикальний тон + верхній inset-highlight (без ghost-card: жодних широких тіней поруч із бордером). Wallet-картка балансу — з м'яким mint-glow орбом. Модалка — без бордера, глибока тінь + inset-highlight, поверхня card-2.
- **Кнопки**: primary (mint, mint-ink текст, `.btn-glow`), ghost (border-line), danger-ghost (revoke), danger-solid (підтвердження в модалці). Всі: `.pressable .focus-ring`, min-h 10–11, повний словник станів (hover/focus-visible/active/disabled/loading).
- **Поля**: bg-canvas на card, border-line → border-mint на фокус, focus-ring.
- **Бейджі статусів**: mint-deep/mint (applied), danger-deep/danger (rejected), card-2/ink-faint (revoked).
- **Сегмент-контрол фільтрів**: контейнер card-2, активний таб — «втиснутий» canvas.
- **Скелетони** першого завантаження (3 рядки, animate-pulse) — не спінер у центрі.
- **Порожні стани навчають**: підказують наступну дію, різний текст для фільтра і порожньої історії.
- **Focus ring**: 2px mint, offset 2px, тільки `:focus-visible`.

## Accessibility

Контраст перевірено на живій сторінці (canvas-нормалізація кольорів): всі пари ≥4.55:1, більшість 5–15:1. `role="dialog"` + aria-modal + фокус + Esc на модалці; `role="alert"/"status"` на повідомленнях; label на всіх полях; toast-повідомлення успіху авто-зникають за 5s, помилки лишаються.
