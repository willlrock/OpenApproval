# Самостоятельное развёртывание

Первая открытая версия — beta. Начните с отдельной установки и вымышленных
данных. SQLite подходит для локального знакомства; рабочую установку и
конкурентные изменения проверяйте с PostgreSQL 16.

## Docker и PostgreSQL

Нужны Docker Engine с Compose v2, доступ к Docker Hub, npm registry и Packagist
во время сборки. Образ содержит PHP, nginx, очередь, scheduler, LibreOffice,
Poppler и Python с OpenCV. Первый build может занять несколько минут.

Скопируйте `.env.example` в `.env`. Оставьте `DB_CONNECTION=pgsql` и
`DB_HOST=postgres`, задайте постоянный случайный `DB_PASSWORD` и `APP_KEY`.
На компьютере с PHP ключ можно получить без установки приложения:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
docker compose up --build -d
docker compose exec app php artisan db:seed
docker compose exec app php artisan admin:bootstrap
docker compose ps
```

Ключ вставьте в `.env` как `APP_KEY=base64:...`. Сохраните его вместе с
резервными копиями: при обновлениях он не должен меняться.
`db:seed` используйте только для начального заполнения демонстрационной базы.
Реальные проекты и отделы создаются через административный интерфейс.

По умолчанию HTTP доступен на `127.0.0.1:8000`, PostgreSQL наружу не публикуется.
В production поставьте перед ним HTTPS reverse proxy. Укажите реальный
`APP_URL`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`,
`TRUSTED_PROXIES` с адресами своих прокси. Не задавайте `*`.
При необходимости измените `APP_BIND_ADDRESS`; открывать HTTP всему интернету
без настроенного HTTPS не следует.

Compose запускает миграции при старте. Перед обновлением с рабочими данными
проверьте миграции на копии и сделайте резервную копию. Для контролируемой
активации можно задать `RUN_MIGRATIONS=false` и выполнить миграции отдельно.

## Фоновые процессы и проверка состояния

Для доставки сообщений нужны `queue:work` и `schedule:work`
(либо `schedule:run` каждую минуту). В Docker они запускаются Supervisor.
Для native-установки организуйте их запуск и перезапуск самостоятельно.
Используйте постоянные `QUEUE_CONNECTION=database` и `CACHE_STORE=database`.

- `/up` проверяет HTTP-приложение.
- `/health/ready` проверяет БД и heartbeat очереди/scheduler за пять минут.
  После старта подождите первую минуту; постоянный 503 требует проверки workers.
- `php artisan queue:failed` показывает задания, требующие внимания.

## Дополнительные интеграции

**Telegram:** создайте собственного бота. Задайте `TELEGRAM_TOKEN`, внешний
HTTPS `TELEGRAM_WEBHOOK_URL=https://approval.example.test/telegram/webhook`
и независимый случайный `TELEGRAM_WEBHOOK_SECRET`. Зарегистрируйте endpoint:

```bash
php artisan telegram:webhook set
```

В Docker выполняйте Artisan через `docker compose exec app php artisan ...`.
Не запускайте polling вместе с webhook. Необязательный
`TELEGRAM_CHANNEL_INVITE_URL` добавляет кнопку приглашения после одобрения
регистрации. Пустое значение отключает только эту кнопку.
Аккаунт сайта привязывается к Telegram одноразовым кодом из профиля.

**Документы:** обычные PDF формирует Dompdf. Для DOCX-приказов и их PDF нужны
LibreOffice (`SOFFICE_BINARY`) и `pdfunite` (`PDFUNITE_BINARY`); для импорта PDF —
`pdftotext` (`PDFTOTEXT_BINARY`). На native Linux установите LibreOffice Writer,
Poppler и шрифты с кириллицей. Очистка фото подписи использует Python 3,
`opencv-python` и `numpy`; при их отсутствии остаётся исходное фото.

**Google Sheets:** подключается через `GOOGLE_APPLICATION_CREDENTIALS`,
`GOOGLE_SHEETS_SPREADSHEET_ID` и `GOOGLE_SHEETS_SHEET_NAME`. Ключ service account
монтируется отдельно, не включается в образ и Git.

**Sentry:** пустые `SENTRY_LARAVEL_DSN` и `SENTRY_FRONTEND_DSN` отключают
отправку. При включении используйте свой проект и проверьте содержание
отправляемых событий на демонстрационных данных.

Telegram, Google Sheets и Sentry при включении требуют исходящего доступа к
соответствующим сервисам. Данные обрабатываются по условиям этих сервисов.
Веб-интерфейс также загружает шрифт Instrument Sans с `fonts.bunny.net`;
для полностью автономной сети разместите шрифт локально и замените ссылки
в `resources/views/app.blade.php`.

## Резервные копии

Сохраняйте согласованную копию PostgreSQL, public/private storage и `.env`
в отдельном защищённом месте. Для самой простой согласованной копии временно
остановите приложение и workers, оставив PostgreSQL запущенным. Перед
возобновлением работы завершите копирование и проверьте результаты команд.
Проверяйте восстановление на отдельном окружении; копия только базы не
содержит подписи, вложения и документы.
`docker compose down -v` удаляет volumes и не является способом обновления.

Внутренние инструкции серверов и автоматический production-deploy исходной
установки не являются частью публичной поставки.
