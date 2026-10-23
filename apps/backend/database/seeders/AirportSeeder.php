<?php

namespace Database\Seeders;

use App\Domain\Airlines\Models\Airport;
use Illuminate\Database\Seeder;

/**
 * Reference airports (Iraq, region and major hubs). The Airlines API does not provide an
 * airport list, so this static data backs the search form. Extend as needed.
 */
class AirportSeeder extends Seeder
{
    /** iata, icao, name_en, name_ar, city_en, city_ar, country, timezone */
    private const AIRPORTS = [
        ['BGW', 'ORBI', 'Baghdad International Airport', 'مطار بغداد الدولي', 'Baghdad', 'بغداد', 'IQ', 'Asia/Baghdad'],
        ['BSR', 'ORMM', 'Basra International Airport', 'مطار البصرة الدولي', 'Basra', 'البصرة', 'IQ', 'Asia/Baghdad'],
        ['EBL', 'ORER', 'Erbil International Airport', 'مطار أربيل الدولي', 'Erbil', 'أربيل', 'IQ', 'Asia/Baghdad'],
        ['NJF', 'ORNI', 'Al Najaf International Airport', 'مطار النجف الأشرف الدولي', 'Najaf', 'النجف', 'IQ', 'Asia/Baghdad'],
        ['ISU', 'ORSU', 'Sulaimaniyah International Airport', 'مطار السليمانية الدولي', 'Sulaymaniyah', 'السليمانية', 'IQ', 'Asia/Baghdad'],
        ['OSM', 'ORBM', 'Mosul International Airport', 'مطار الموصل الدولي', 'Mosul', 'الموصل', 'IQ', 'Asia/Baghdad'],
        ['DXB', 'OMDB', 'Dubai International Airport', 'مطار دبي الدولي', 'Dubai', 'دبي', 'AE', 'Asia/Dubai'],
        ['AUH', 'OMAA', 'Zayed International Airport', 'مطار زايد الدولي', 'Abu Dhabi', 'أبوظبي', 'AE', 'Asia/Dubai'],
        ['SHJ', 'OMSJ', 'Sharjah International Airport', 'مطار الشارقة الدولي', 'Sharjah', 'الشارقة', 'AE', 'Asia/Dubai'],
        ['DOH', 'OTHH', 'Hamad International Airport', 'مطار حمد الدولي', 'Doha', 'الدوحة', 'QA', 'Asia/Qatar'],
        ['BAH', 'OBBI', 'Bahrain International Airport', 'مطار البحرين الدولي', 'Manama', 'المنامة', 'BH', 'Asia/Bahrain'],
        ['KWI', 'OKKK', 'Kuwait International Airport', 'مطار الكويت الدولي', 'Kuwait City', 'الكويت', 'KW', 'Asia/Kuwait'],
        ['MCT', 'OOMS', 'Muscat International Airport', 'مطار مسقط الدولي', 'Muscat', 'مسقط', 'OM', 'Asia/Muscat'],
        ['RUH', 'OERK', 'King Khalid International Airport', 'مطار الملك خالد الدولي', 'Riyadh', 'الرياض', 'SA', 'Asia/Riyadh'],
        ['JED', 'OEJN', 'King Abdulaziz International Airport', 'مطار الملك عبدالعزيز الدولي', 'Jeddah', 'جدة', 'SA', 'Asia/Riyadh'],
        ['MED', 'OEMA', 'Prince Mohammad bin Abdulaziz Airport', 'مطار الأمير محمد بن عبدالعزيز', 'Madinah', 'المدينة المنورة', 'SA', 'Asia/Riyadh'],
        ['DMM', 'OEDF', 'King Fahd International Airport', 'مطار الملك فهد الدولي', 'Dammam', 'الدمام', 'SA', 'Asia/Riyadh'],
        ['AMM', 'OJAI', 'Queen Alia International Airport', 'مطار الملكة علياء الدولي', 'Amman', 'عمّان', 'JO', 'Asia/Amman'],
        ['BEY', 'OLBA', 'Beirut–Rafic Hariri International Airport', 'مطار رفيق الحريري الدولي', 'Beirut', 'بيروت', 'LB', 'Asia/Beirut'],
        ['DAM', 'OSDI', 'Damascus International Airport', 'مطار دمشق الدولي', 'Damascus', 'دمشق', 'SY', 'Asia/Damascus'],
        ['CAI', 'HECA', 'Cairo International Airport', 'مطار القاهرة الدولي', 'Cairo', 'القاهرة', 'EG', 'Africa/Cairo'],
        ['IST', 'LTFM', 'Istanbul Airport', 'مطار إسطنبول', 'Istanbul', 'إسطنبول', 'TR', 'Europe/Istanbul'],
        ['SAW', 'LTFJ', 'Sabiha Gökçen International Airport', 'مطار صبيحة كوكجن الدولي', 'Istanbul', 'إسطنبول', 'TR', 'Europe/Istanbul'],
        ['AYT', 'LTAI', 'Antalya Airport', 'مطار أنطاليا', 'Antalya', 'أنطاليا', 'TR', 'Europe/Istanbul'],
        ['IKA', 'OIIE', 'Imam Khomeini International Airport', 'مطار الإمام الخميني الدولي', 'Tehran', 'طهران', 'IR', 'Asia/Tehran'],
        ['MHD', 'OIMM', 'Mashhad International Airport', 'مطار مشهد الدولي', 'Mashhad', 'مشهد', 'IR', 'Asia/Tehran'],
        ['LHR', 'EGLL', 'London Heathrow Airport', 'مطار هيثرو', 'London', 'لندن', 'GB', 'Europe/London'],
        ['FRA', 'EDDF', 'Frankfurt Airport', 'مطار فرانكفورت', 'Frankfurt', 'فرانكفورت', 'DE', 'Europe/Berlin'],
        ['CDG', 'LFPG', 'Paris Charles de Gaulle Airport', 'مطار شارل ديغول', 'Paris', 'باريس', 'FR', 'Europe/Paris'],
        ['AMS', 'EHAM', 'Amsterdam Airport Schiphol', 'مطار سخيبول', 'Amsterdam', 'أمستردام', 'NL', 'Europe/Amsterdam'],
        ['ARN', 'ESSA', 'Stockholm Arlanda Airport', 'مطار أرلاندا', 'Stockholm', 'ستوكهولم', 'SE', 'Europe/Stockholm'],
        ['VIE', 'LOWW', 'Vienna International Airport', 'مطار فيينا الدولي', 'Vienna', 'فيينا', 'AT', 'Europe/Vienna'],
        ['KUL', 'WMKK', 'Kuala Lumpur International Airport', 'مطار كوالالمبور الدولي', 'Kuala Lumpur', 'كوالالمبور', 'MY', 'Asia/Kuala_Lumpur'],
        ['BOM', 'VABB', 'Chhatrapati Shivaji Maharaj International Airport', 'مطار مومباي الدولي', 'Mumbai', 'مومباي', 'IN', 'Asia/Kolkata'],
        ['DEL', 'VIDP', 'Indira Gandhi International Airport', 'مطار إنديرا غاندي الدولي', 'Delhi', 'دلهي', 'IN', 'Asia/Kolkata'],
        ['JFK', 'KJFK', 'John F. Kennedy International Airport', 'مطار جون كينيدي الدولي', 'New York', 'نيويورك', 'US', 'America/New_York'],
    ];

    public function run(): void
    {
        foreach (self::AIRPORTS as [$iata, $icao, $nameEn, $nameAr, $cityEn, $cityAr, $country, $tz]) {
            Airport::query()->updateOrCreate(['iata_code' => $iata], [
                'icao_code' => $icao, 'name_en' => $nameEn, 'name_ar' => $nameAr, 'city_en' => $cityEn,
                'city_ar' => $cityAr, 'country_code' => $country, 'timezone' => $tz, 'is_active' => true,
            ]);
        }
    }
}
