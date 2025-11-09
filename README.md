# Ezan Saati Kiosk Uygulaması

Bu proje, MySQL veritabanı ile çalışan ve kiosk modunda kullanılmak üzere tasarlanmış web tabanlı bir ezan saati uygulamasıdır. Yönetim paneli üzerinden konum ayarları yapılabilir, vakitler otomatik olarak güncellenir ve her namaz için farklı ezan sesleri tanımlanabilir. Sistem ezan vakti geldiğinde belirlenen ses dosyalarını otomatik olarak çalar.

## Özellikler

- Şifre korumalı yönetim paneli
- Aladhan API üzerinden günlük namaz vakitlerinin otomatik güncellenmesi
- Ülke/il/ilçe seçimi, hesaplama metodu ve mezhep ayarları
- Beş vakit ezanı için ayrı ses dosyaları yükleyebilme
- Cuma selası için özel ses dosyası ve ezandan önceki süreyi belirleme
- Kiosk moduna uygun tam ekran arayüz, canlı saat, geri sayım ve yaklaşan vakit listesi

## Kurulum

1. Kaynakları sunucuya veya geliştirme ortamına kopyalayın.
2. `database/schema.sql` dosyasını MySQL veritabanınıza uygulayın. (Varsayılan veritabanı adı `signage` olarak ayarlanmıştır.)
3. `config/config.php` dosyasındaki veritabanı bilgilerinin sunucunuza uygun olduğundan emin olun. Gerekirse çevresel değişkenler (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) ile override edebilirsiniz.
4. PHP sunucunuzun kök dizinini bu projenin kök dizinine yönlendirin.

### Varsayılan yönetici hesabı

- Kullanıcı adı: `admin`
- Şifre: `admin123`

## Kullanım

- Yönetim paneli: `/admin/login.php`
- Kiosk arayüzü: `/index.php`

### Namaz vakitlerini güncelleme

Yönetim panelinde konum bilgilerini kaydederseniz sistem otomatik olarak seçilen konum için namaz vakitlerini alır ve veritabanına kaydeder. İhtiyaç halinde paneldeki "Namaz Vakitlerini Yenile" butonu ile manuel olarak da güncelleme yapabilirsiniz.

### Ses dosyalarını yönetme

Her namaz vakti için ayrı ses dosyası yükleyebilir, güncelleyebilir veya silebilirsiniz. Yüklenen dosyalar `public/uploads/audio` dizininde saklanır ve ezan vakti geldiğinde otomatik olarak oynatılır.

## Geliştirme

- Aladhan API kullanımında herhangi bir kota sınırına takılmamak için istek sayısını minimal tutacak şekilde cache mekanizması kullanılmıştır.
- Kiosk arayüzü otomatik olarak 5 dakikada bir veri tazeler; dilerseniz `assets/prayer.js` içindeki değerleri güncelleyebilirsiniz.
- Cuma selası, ilgili Cuma günü öğle vaktinden belirlediğiniz dakika kadar önce oynatılır.
