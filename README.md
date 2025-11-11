# Signage

PHP tabanlı bu proje, MySQL (phpMyAdmin) veritabanına bağlı çalışan basit bir kayan yazı (digital signage) uygulamasıdır. Yönetim paneli üzerinden içerik eklenebilir, web sayfası ve gömülebilir widget ile yayınlanabilir.

## Özellikler

- Şifre korumalı yönetim paneli
- Sarı / siyah / beyaz renk paletine uygun modern arayüz
- Başlık ve açıklama alanlarından oluşan çoklu mesaj desteği
- Mesaj başına renk seçimi ve kayma süresi ayarı
- Ana ekran ve widget için otomatik kayan yazı bileşeni
- Ürün indirimleri için görselli LED slider (1536x384 uyumlu)

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
- Kayan yazı önizlemesi: `/index.php`
- Widget sayfası: `/public/widget.php`
- LED ürün slider'ı: `/public/led-products.php`

Widget sayfasını başka sitelere `<iframe src="https://alanadiniz.com/public/widget.php" width="800" height="150"></iframe>` benzeri bir kodla ekleyebilirsiniz.

## Geliştirme İpuçları

- Mesaj sıralaması için `position` alanı kullanılmaktadır. Gerektiğinde bu alan üzerinden manuel sıralama yapılabilir.
- Tasarım için `assets/styles.css` dosyasını kullanabilirsiniz.
