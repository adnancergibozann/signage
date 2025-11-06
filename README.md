# Gapgross Signage Platform

Gapgross idari binası için geliştirilen bu proje, satınalma müdürlerinin durumlarını, kurumsal duyuruları ve zamanlanmış medya yayınlarını tek bir signage ekranında toplamayı amaçlar. Süper yönetici paneli üzerinden içerikler ve kullanıcılar yönetilebilir, satınalma müdürleri ise kendi durumlarını anlık olarak güncelleyebilir.

## Başlıca Özellikler

- **Roller:** `super_admin`, `boss`, `manager`, `finance`, `accounting`, `secretary`, `viewer` rollerine göre yetkilendirme.
- **Signage ekranı:** Portre (1080×1920) uyumlu tek sayfa; kurum logosu, saat/tarih, satınalma müdür kartları, duyurular, organigram modali ve kayan yazı alanı.
- **Tam ekran medya:** Zamanlanmış resim/video yayınları; aktif olduğunda tüm ekranı kaplayarak oynatılır.
- **Durum yönetimi:** Müdür kartlarında müsaitlik, toplantı geri sayımı, yemek ve izin durumları.
- **Planlı görüşme ajandası:** Sekreter panelinden tüm yöneticiler için görüşme planlama, durum güncelleme ve geçmiş kayıt takibi; yöneticiler kendi yaklaşan görüşmelerini görebilir.
- **Sekreter çalışma alanı:** Toplu durum değişikliği, günlük yemek molası saatleri, duyuru ve kayan yazı yönetimi tek ekranda.
- **Duyuru & ticker yönetimi:** Metin bazlı duyurular, kayan yazı öğeleri, öncelik ve tarih aralıklarıyla planlama.
- **Zamanlanmış uyarılar:** Yemek molası gibi kurumsal uyarılar için zaman penceresi tanımlama.
- **Raporlama:** Günlük toplantı raporu (boss rolü) ve manuel durum yönetimi (super admin).

## Kurulum

1. Kaynak kodu sunucuya veya geliştirme ortamınıza kopyalayın.
2. `database/schema.sql` dosyasını MySQL veritabanınıza uygulayın.
3. `config/config.php` içindeki bağlantı bilgilerini ortamınıza göre düzenleyin veya `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` değişkenleriyle override edin.
4. `public/uploads/` dizinlerinin web sunucusu tarafından yazılabilir olduğundan emin olun.
5. Projeyi PHP 8.1+ çalıştıran bir sunucuda konumlandırın.

### Varsayılan hesaplar

| Rol          | Kullanıcı adı | Şifre     |
|--------------|---------------|-----------|
| super_admin  | `admin`       | `admin123`|
| boss         | `boss`        | `boss123` |
| manager      | `ayse`        | `manager123`|
| manager      | `mehmet`      | `manager123`|

## Geliştirme

- PHP tarafında modern, strict type deklarasyonları tercih edilmiştir.
- Zaman dilimi Europe/Istanbul olarak ayarlanmıştır.
- Signage ekranı varsayılan olarak her 5 saniyede bir `/public/api/signage.php` uç noktasından güncel veriyi çeker. Bu değer yönetim paneli ayarlarından değiştirilebilir.
- Medya yüklemeleri `public/uploads/media/`, profil fotoğrafları `public/uploads/profile/`, marka ögeleri ise `public/uploads/branding/` altında saklanır.

## Test

Projede henüz otomatik test bulunmamaktadır. Manuel olarak yönetim panelini ve signage ekranını kontrol ederek doğrulama yapabilirsiniz.
