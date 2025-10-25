# Ders Programı Yönetim Paneli ve Widget'ları

Bu proje, XAMPP altında `/htdocs/ders-programi` klasörüne kopyalanarak tamamen istemci tarafında çalışan bir ders programı yönetim paneli ve iki adet gömülebilir widget sağlar.

## Dosya Yapısı

- `public/index.html`: Yönetim paneli giriş noktası.
- `public/app.js`: Yönetim panelinin işleyişi.
- `public/widget.js`: Herhangi bir sayfaya gömülebilen geri sayım ve ders programı widget'ları.
- `public/styles.css`: Ortak stil dosyası.
- `public/widget-demo.html`: Widget'ların nasıl gömüleceğini gösteren örnek sayfa.
- `public/widget-countdown.html`: Geri sayım widget'ı için bağımsız örnek sayfa.
- `public/widget-schedule.html`: Ders programı widget'ı için bağımsız örnek sayfa.

## Yönetim Panelini Çalıştırma

1. Proje klasörünü XAMPP'in `htdocs/ders-programi` dizinine kopyalayın.
2. Tarayıcıdan `http://localhost/ders-programi/public/index.html` adresini açın.
3. İlk açılışta yönetici şifresi oluşturmanız istenir. Girilen şifre SHA-256 ile tarayıcıda hashlenip `localStorage` içinde saklanır.
4. Şifre oluşturulduktan sonra panel kilitlendiğinde tekrar giriş yapmak için aynı şifreyi kullanın.
5. Sınıf, öğretmen, ders, slot ve haftalık program verilerini panel üzerinden düzenleyebilirsiniz. Tüm veriler `localStorage` içinde tutulur.
6. Sağ üstteki **JSON Dışa Aktar** ve **JSON İçeri Al** kontrolleri ile yedekleme yapabilirsiniz.
7. "Kilitle" düğmesi oturumu kapatır ve paneli tekrar şifre ister hale getirir.

## Widget'ları Gömme

Widget'lar, yönetim paneli ile aynı tarayıcı `localStorage` alanını kullanır. Panelde oluşturduğunuz veriler widget'lar tarafından otomatik okunur.

1. Widget'ı göstermek istediğiniz sayfada aşağıdaki yapıyı ekleyin:

   ```html
   <div data-widget="countdown" data-class="10-A"></div>
   <div data-widget="schedule" data-class="10-A" data-view="daily,weekly"></div>
   <script src="/ders-programi/public/widget.js"></script>
   ```

   - `data-widget="countdown"` geri sayım widget'ını açar.
   - `data-widget="schedule"` ders programı kartını açar.
   - `data-class` veya `data-class-id` ile sınıfı adı ya da benzersiz kimliği üzerinden seçebilirsiniz. Eğer bu öznitelikler verilmezse, ilk tanımlı sınıf kullanılır.
   - `data-view` özniteliği için `daily` (varsayılan) ve `weekly` değerlerini virgülle ayırarak aynı kartta günlük ve/veya haftalık görünüm isteyebilirsiniz.

2. Tek sayfada birden fazla widget kullanabilirsiniz. `widget.js` dosyasını **sayfanın sonunda** bir kez eklemek yeterlidir.
3. Widget'lar otomatik olarak kendi stillerini yükler ve her 60 saniyede bir verileri tazeler. Yönetim panelinde yaptığınız değişiklikler tarayıcı sekmeleri arasında otomatik olarak senkronize edilir.

## Depolama Uyarısı

Tüm veriler tarayıcının `localStorage` alanında tutulur. Yaklaşık 5–10 MB sınırı aşıldığında panel üstünden uyarı alırsınız. Düzenli olarak JSON dışa aktarımı yaparak yedeklemeniz önerilir.

## Tarayıcı ve Dil Ayarları

- Saat ve tarih işlemleri `Europe/Istanbul` zaman dilimine göre ve `tr-TR` yerel ayarına göre formatlanır.
- Uygulama saf HTML, CSS ve Vanilla JS (ES6) kullanır; herhangi bir backend servisine ihtiyaç duymaz.

