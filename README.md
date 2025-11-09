# Adhan Scheduler & Player

Linux tabanlı, web üzerinden yönetilen ezan zamanlama ve oynatma uygulaması.

## Özellikler

- Ülke → Şehir → İlçe seçimi veya manuel koordinat girerek konum ayarı
- Adhan kütüphanesi ile birden fazla hesaplama metoduna destek
- Günlük vakitlerin hesaplanması, 7 gün ileriye kayıt
- node-cron ile otomatik zamanlama, sunucu açıldığında yeniden planlama
- ffplay/mpg123 üzerinden sunucu taraflı ses oynatma, fade-in/out
- Ses setleri oluşturma, her vakit için ayrı dosya atama
- Web tabanlı yönetim paneli (Tailwind + vanilla JS)
- SQLite veritabanı, bcrypt ile kullanıcı doğrulaması
- REST API uç noktaları

## Başlangıç

### Gereksinimler

- Node.js 18+
- ffplay (ffmpeg) veya mpg123

### Kurulum

```bash
cp .env.example .env
npm install
npm run dev
```

Varsayılan yönetici hesabı: `admin@example.com` / `admin123`

### Üretim kurulumu (örnek)

```bash
# Uygulama dizini
sudo mkdir -p /opt/adhan
sudo chown $USER /opt/adhan

# Kaynakları kopyala
cp -r * /opt/adhan
cd /opt/adhan
npm install --production

# Ortam dosyası
cp .env.example .env
vi .env
```

Tailwind CDN üzerinden yüklendiği için ek derleme gerektirmez.

## Sistem servisi

`config/systemd/adhan-scheduler.service` dosyasını `/etc/systemd/system/` içine kopyalayın ve düzenleyin.

```bash
sudo cp config/systemd/adhan-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable adhan-scheduler
sudo systemctl start adhan-scheduler
```

## API Özeti

- `POST /api/login` – yönetici oturumu aç
- `GET /api/times?date=YYYY-MM-DD` – hesaplanan vakitler
- `POST /api/settings` – konum, metod, offset ve ses ayarlarını kaydet
- `GET /api/settings` – mevcut ayarlar
- `GET /api/locations` – konum listesi
- `POST /api/voices` – yeni ses seti oluştur
- `POST /api/voices/:id/upload?prayer=fajr|…` – vakit için dosya yükle
- `POST /api/active-voice` – aktif ses setini belirle
- `GET /api/voices` – ses setlerini listele
- `GET /api/schedule` – gelecek tetikleyiciler
- `GET /api/logs` – oynatma günlükleri
- `POST /api/test-play` – seçili vakti hemen oynat
- `POST /api/stop` – mevcut çalmayı durdur
- `POST /api/snooze` – planlamayı geçici olarak duraklat

## Testler

```bash
npm test
```

## Ses oynatma

Varsayılan olarak `ffplay` kullanılır. `AUDIO_CMD` ve `AUDIO_DEVICE` ortam değişkenleriyle özelleştirebilirsiniz. Fade efektleri ffplay üzerinde `afade` filtresiyle uygulanır. mpg123 kullanırsanız fade devre dışı kalabilir.

## Veritabanı

`database.sqlite` uygulama kökünde oluşur. Varsayılan veriler ve tablo şeması ilk çalıştırmada otomatik oluşturulur. Ses dosyaları `public/uploads/` dizinine kaydedilir.

## Güvenlik

- Oturumlar SQLite destekli session store ile saklanır
- Parolalar bcrypt ile hashlenir
- `/api/*` uç noktaları oturum doğrulaması gerektirir

## Günlükler

Her oynatma denemesi `play_logs` tablosuna başarı/başarısızlık ve çıktı mesajlarıyla kaydedilir. Yönetim panelindeki “Kayıtlar” sekmesinden görülebilir.
