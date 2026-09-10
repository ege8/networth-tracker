# Net Worth Tracker

Tek kullanıcılı, kişisel **Net Worth Tracker**. Auth yok — uygulama yalnızca
LAN içinde erişilebilir olacak şekilde tasarlandı.

## Özellikler

- **Tek kullanıcı, auth yok.** Login ekranı, session yönetimi ve
  kullanıcı-başına dosyalar yok. Tüm veri tek dosyada:
  `www/data/state.json`.
- **`admin.php` tamamen silindi.** Admin paneli ve toplu kâr dağıtımı
  (`PROFIT_CAP_USD`, `bulk_profit`, `apply_profit` vb.) kaldırıldı.
- **Varlık yönetimi.** Varlıklar silinebilir/adı değiştirilebilir.
  Yeni kurulumda varlık listesi boş başlar — istediğini ekle.
- **Borç/negatif varlık desteği.** Bir varlığın taban tutarını negatif girersen
  (ör. "Kredi Kartı Borcu": −500) otomatik olarak borç sayılır, kırmızı
  gösterilir ve **Net Worth = Varlıklar − Borçlar** olarak hesaplanır. Ayrı bir
  "borç" alanı/checkbox'ı yok — bu en basit ve mevcut veri modeliyle tam uyumlu
  yöntem.
- **Kripto desteği.** Yeni birimler: **BTC** ve **ETH**. Fiyatlar CoinGecko'nun
  ücretsiz `simple/price` uç noktasından (API key gerekmez) 5 dakikalık
  önbellekle çekilir. **XAUT** fiyatı da artık öncelikle CoinGecko'dan
  (`tether-gold`) alınır; alınamazsa gram altın kurundan türetilir (eski
  davranış, yedek olarak korundu).
- **Net worth grafiği.** Geçmiş (`history`) verisinden Chart.js ile çizgi
  grafik — "90 Gün" / "Tümü" aralık seçimi ve TL/USD toggle'ı ile birlikte
  çalışır.
- **Aylık büyüme kartı.** Özet bölümünde "geçen aya göre +%X (+$Y)" kartı.
- **Basitleştirilmiş API** — aşağıya bakın. `?api=cron` artık key istemiyor
  (LAN içinde güvenli kabul edildi) ve tüm kullanıcılar yerine tek state'i
  günceller.
- **Geriye dönük uyumlu içe aktarma.** `?api=import` ile manuel JSON içe
  aktarma çalışır.

> **Not — `data.json` hakkında:** Yüklenen `data.json` dosyası (Keith/Ege/Utku/Rosea
> anahtarlı, `darkexAmount`/`otherAmount` alanlı) tamamen farklı bir şemaya
> sahip — bu bir işlem/pozisyon takip verisi. Net Worth Tracker'ın içe aktarma
> doğrulaması (`assets` alanı zorunlu) bu dosyayı haklı olarak reddeder. Bu
> veriyi taşımak istersen ayrı bir görev olarak ele almak gerekir.

## Dosya yapısı

```
networth/
├── docker-compose.yml
├── Dockerfile            (php:8.3-apache + mbstring eklentisi)
├── www/
│   ├── index.php         (UI + basitleştirilmiş API uçları)
│   ├── lib.php            (state yönetimi, kur/kripto/hisse çekme, hesaplamalar)
│   └── data/               (state.json burada tutulur — yazılabilir olmalı)
└── README.md
```

> Neden Dockerfile? Resmi `php:8.3-apache` imajında **mbstring** eklentisi
> varsayılan olarak yok, `lib.php` ise `mb_substr`/`mb_strtolower` kullanıyor.
> Bu yüzden `docker-compose.yml` düz imaj yerine yerel `Dockerfile`'ı build
> ediyor (`build: .`).

## Kurulum (Raspberry Pi 5, Docker)

```bash
# 1) Proje klasörünü Pi'ye kopyala (scp/git/rsync — tercihine göre)
cd networth

# 2) data/ klasörünü www-data'nın (uid 33) yazabileceği hale getir
sudo chown -R 33:33 www/data
sudo chmod -R 775 www/data

# 3) Başlat
docker compose up -d --build

# 4) Kontrol
docker compose logs -f
```

Erişim: `http://<pi-ip>:8095`

### Güvenlik notu

Auth **yok**. `docker-compose.yml` sadece host ağına (`0.0.0.0:8095`) bind
eder — bu LAN içinde her cihazdan erişilebilir demek, ama **router'da bu portu
NAT/port-forward ile internete açmayın**. Uygulamayı yalnızca güvenilir yerel
ağınızda kullanın.

### Günlük otomatik kayıt (cron)

`?api=cron` uç noktası kurları/kripto fiyatlarını/hisse fiyatlarını günceller
ve günlük bir net worth kaydı (`history`) ekler. Key gerekmez (LAN içi).
Pi'nin kendi crontab'ına ekle:

```
crontab -e
```

ve şu satırı ekle (her gün 09:00'da çalışır):

```
0 9 * * * curl -s http://localhost:8095/?api=cron >/dev/null 2>&1
```

## API uçları

| Uç nokta | Yöntem | Açıklama |
|---|---|---|
| `?api=state` | GET | State'i oku (tekrarlayan işlemleri de işler) |
| `?api=state` | POST | State'i kaydet |
| `?api=snapshot` | POST | `{usd}` ile günlük geçmiş kaydı ekle |
| `?api=rates` | GET | Canlı kur (TL/EUR/Altın) + kripto (BTC/ETH/XAUT), önbellekli |
| `?api=stockprices` | GET | BIST hisse fiyatları (Yahoo Finance), önbellekli |
| `?api=cron` | GET | Tüm kurları/fiyatları güncelle + günlük snapshot al |
| `?api=export` | GET | State'i JSON olarak indir |
| `?api=import` | POST | JSON dosyasından state'i geri yükle (mevcut verinin üzerine yazar) |

## Korunan özellikler

- USD/TL/EUR/Altın/XAUT/**BTC/ETH** birimli varlık sistemi, `rate_to_usd` hesabı
- Canlı kur: finans.truncgil.com v4 + open.er-api.com fallback
- BIST hisse fiyatları (Yahoo Finance) + lot/alış/kâr-zarar
- Gelir/gider işlemleri ve varlığa bağlanma (`asset_effective_usd`)
- Tekrarlayan aylık işlemler (`apply_recurring`)
- Günlük snapshot geçmişi (`history`, en fazla 730 gün)
- JSON export/import
- Koyu tema UI (Cormorant Garamond + Outfit fontları, rose/gold palet), mobil
  responsive
