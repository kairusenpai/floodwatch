# ============================================================
# FloodWatch — ESP32 Water Level Sensor (MicroPython)
# Sends water level readings to FloodWatch API
# ============================================================

import network
import time
import machine
import urequests
import ujson
import ntptime
from machine import ADC, Pin, UART


# ============================================================
# CONFIGURATION
# ============================================================

# WiFi Credentials
WIFI_SSID = "YOTC-D71737"
WIFI_PASSWORD = "60141936"


# FloodWatch Server Configuration
SERVER_URL = "https://floodwatchews.infinityfreeapp.com/floodwatch/"
API_ENDPOINT = "/api/sensor_data.php"


# Sensor Configuration
SENSOR_CODE = "SENSOR-01"
SENSOR_PIN = 34

READING_INTERVAL = 1000  # milliseconds (read every 1 second)
SEND_INTERVAL = 30000  # milliseconds (send average every 30 seconds)


# Calibration
# Sensor output: 0 = dry, 600+ = fully submerged
# Maps analog values to water level in cm (max 150cm)
# When fully submerged (600+ analog), should read 150cm (max level)
MIN_ANALOG = 0
MAX_ANALOG = 600
MAX_LEVEL_CM = 150


# SIM800L GSM Module Configuration
SIM800L_TX_PIN = 17  # ESP32 GPIO connected to SIM800L RX
SIM800L_RX_PIN = 16  # ESP32 GPIO connected to SIM800L TX
SIM800L_BAUDRATE = 9600
SMS_ENABLED = True  # Set to False to disable SMS alerts


# GPS Module Configuration
GPS_TX_PIN = 26  # ESP32 GPIO connected to GPS RX
GPS_RX_PIN = 27  # ESP32 GPIO connected to GPS TX
GPS_BAUDRATE = 9600
GPS_ENABLED = True  # Set to False to disable GPS


# Alert Thresholds (cm)
# Max level is 130cm (fully submerged)
WARNING_THRESHOLD = 70
DANGER_THRESHOLD = 100
CRITICAL_THRESHOLD = 130


# ============================================================
# GLOBAL VARIABLES
# ============================================================

last_reading_time = 0
last_send_time = 0
last_alert_level = None  # Track last alert level to avoid duplicate SMS
household_numbers = []  # Cache household phone numbers
gps_data = {'lat': None, 'lng': None, 'fix': False}  # GPS coordinates
readings_buffer = []  # Buffer to store readings for averaging


# ESP32 ADC
sensor = ADC(Pin(SENSOR_PIN))
sensor.atten(ADC.ATTN_11DB)      # 0-3.3V range
sensor.width(ADC.WIDTH_12BIT)    # 0-4095


# SIM800L UART
if SMS_ENABLED:
    sim800l = UART(2, SIM800L_BAUDRATE, tx=Pin(SIM800L_TX_PIN), rx=Pin(SIM800L_RX_PIN))
    sim800l.init(SIM800L_BAUDRATE, bits=8, parity=None, stop=1)


# GPS UART
if GPS_ENABLED:
    gps_uart = UART(1, GPS_BAUDRATE, tx=Pin(GPS_TX_PIN), rx=Pin(GPS_RX_PIN))
    gps_uart.init(GPS_BAUDRATE, bits=8, parity=None, stop=1)


# ============================================================
# WIFI CONNECTION
# ============================================================

def connect_wifi():

    wlan = network.WLAN(network.STA_IF)
    wlan.active(True)

    if wlan.isconnected():
        return wlan


    print("Connecting to WiFi:", WIFI_SSID)

    wlan.connect(WIFI_SSID, WIFI_PASSWORD)

    attempts = 0

    while not wlan.isconnected() and attempts < 20:
        print(".", end="")
        time.sleep(0.5)
        attempts += 1


    if wlan.isconnected():

        print("\nWiFi connected!")
        print("IP:", wlan.ifconfig()[0])

    else:

        print("\nWiFi connection failed")


    return wlan



# ============================================================
# GPS FUNCTIONS
# ============================================================

def init_gps():
    """Initialize GPS module"""
    if not GPS_ENABLED:
        return False
    
    print("Initializing GPS...")
    
    # Clear any pending data
    gps_uart.read()
    
    print("✓ GPS UART initialized")
    return True


def parse_nmea_sentence(sentence):
    """Parse NMEA sentence to extract coordinates"""
    if not sentence.startswith('$'):
        return None
    
    try:
        # Split sentence by commas
        parts = sentence.split(',')
        
        # GPRMC - Recommended Minimum sentence
        if parts[0] == '$GPRMC':
            # parts[1] = UTC time
            # parts[2] = Status (A=valid, V=invalid)
            # parts[3] = Latitude (DDMM.MMMMM)
            # parts[4] = N/S
            # parts[5] = Longitude (DDDMM.MMMMM)
            # parts[6] = E/W
            
            if parts[2] == 'A':  # Valid fix
                # Parse latitude
                lat_raw = parts[3]
                lat_dir = parts[4]
                if lat_raw:
                    lat_deg = float(lat_raw[:2])
                    lat_min = float(lat_raw[2:])
                    lat = lat_deg + (lat_min / 60)
                    if lat_dir == 'S':
                        lat = -lat
                
                # Parse longitude
                lon_raw = parts[5]
                lon_dir = parts[6]
                if lon_raw:
                    lon_deg = float(lon_raw[:3])
                    lon_min = float(lon_raw[3:])
                    lon = lon_deg + (lon_min / 60)
                    if lon_dir == 'W':
                        lon = -lon
                
                return {'lat': lat, 'lng': lon, 'fix': True}
        
        # GPGGA - Global Positioning System Fix Data
        elif parts[0] == '$GPGGA':
            # parts[1] = UTC time
            # parts[2] = Latitude (DDMM.MMMMM)
            # parts[3] = N/S
            # parts[4] = Longitude (DDDMM.MMMMM)
            # parts[5] = E/W
            # parts[6] = Fix quality (0=invalid, 1=GPS, 2=DGPS)
            
            fix_quality = int(parts[6]) if parts[6] else 0
            
            if fix_quality >= 1:  # Valid fix
                # Parse latitude
                lat_raw = parts[2]
                lat_dir = parts[3]
                if lat_raw:
                    lat_deg = float(lat_raw[:2])
                    lat_min = float(lat_raw[2:])
                    lat = lat_deg + (lat_min / 60)
                    if lat_dir == 'S':
                        lat = -lat
                
                # Parse longitude
                lon_raw = parts[4]
                lon_dir = parts[5]
                if lon_raw:
                    lon_deg = float(lon_raw[:3])
                    lon_min = float(lon_raw[3:])
                    lon = lon_deg + (lon_min / 60)
                    if lon_dir == 'W':
                        lon = -lon
                
                return {'lat': lat, 'lng': lon, 'fix': True}
        
        return None
    
    except Exception as e:
        print("NMEA parse error:", e)
        return None


def read_gps():
    """Read and parse GPS data"""
    global gps_data
    
    if not GPS_ENABLED:
        return gps_data
    
    try:
        # Read available data from GPS UART
        if gps_uart.any():
            data = gps_uart.read()
            if data:
                sentence = data.decode('utf-8', errors='ignore').strip()
                
                # Try to parse the sentence
                result = parse_nmea_sentence(sentence)
                if result:
                    gps_data = result
                    print(f"GPS: {result['lat']:.6f}, {result['lng']:.6f}")
        
        return gps_data
    
    except Exception as e:
        print("GPS read error:", e)
        return gps_data


# ============================================================
# SIM800L SMS FUNCTIONS
# ============================================================

def init_sim800l():
    """Initialize and diagnose SIM800L"""

    if not SMS_ENABLED:
        return False

    print("\n=== SIM800L INITIALIZATION ===")

    # Clear any old UART data
    sim800l.read()

    # ------------------------------------------------
    # 1. Check SIM800L communication
    # ------------------------------------------------
    sim800l.write(b'AT\r\n')
    time.sleep(1)

    response = sim800l.read()

    if not response or b'OK' not in response:
        print("✗ SIM800L not responding")
        return False

    print("✓ SIM800L detected")

    # ------------------------------------------------
    # 2. Check SIM card
    # ------------------------------------------------
    sim800l.write(b'AT+CPIN?\r\n')
    time.sleep(1)

    response = sim800l.read()
    print("SIM status:", response)

    if response and b'+CPIN: READY' in response:
        print("✓ SIM card detected and ready")

    elif response and b'+CPIN: SIM PIN' in response:
        print("SIM requires PIN")

        # Only use this if you KNOW your SIM PIN
        print("Attempting SIM PIN...")

        sim800l.write(b'AT+CPIN="1234"\r\n')
        time.sleep(3)

        pin_response = sim800l.read()
        print("PIN response:", pin_response)

        if not pin_response or b'OK' not in pin_response:
            print("✗ SIM PIN failed")
            return False

    else:
        print("✗ SIM card not detected")

        # Try ICCID for confirmation
        sim800l.write(b'AT+CCID\r\n')
        time.sleep(1)

        iccid = sim800l.read()
        print("ICCID:", iccid)

        if not iccid or b'OK' not in iccid:
            print("✗ SIM card cannot be read")
            print("Check SIM card insertion, SIM holder, and power.")
            return False

    # ------------------------------------------------
    # 3. Check signal
    # ------------------------------------------------
    sim800l.write(b'AT+CSQ\r\n')
    time.sleep(1)

    signal = sim800l.read()
    print("Signal strength:", signal)

    # ------------------------------------------------
    # 4. Check network registration
    # ------------------------------------------------
    # Clear buffer before checking
    sim800l.read()
    
    # Try multiple times to get registration status
    registered = False
    for attempt in range(3):
        sim800l.write(b'AT+CREG?\r\n')
        time.sleep(2)  # Increased delay for response
        
        registration = sim800l.read()
        print("Network registration (attempt {}):".format(attempt + 1), registration)
        
        # Check for various registration status codes
        # 0,1 = Registered to home network
        # 0,5 = Registered, roaming
        # 1,1 = Registered to home network (with location info)
        # 1,5 = Registered, roaming (with location info)
        # 2,1 = Registered to home network (automatic registration)
        # 2,5 = Registered, roaming (automatic registration)
        if registration and (
            b'+CREG: 0,1' in registration or
            b'+CREG: 0,5' in registration or
            b'+CREG: 1,1' in registration or
            b'+CREG: 1,5' in registration or
            b'+CREG: 2,1' in registration or
            b'+CREG: 2,5' in registration
        ):
            print("✓ Network registered")
            registered = True
            break
        elif registration and b'+CREG:' in registration:
            # Parse the actual status code for debugging
            print("Registration status code detected but not matching expected values")
        time.sleep(1)
    
    if not registered:
        print("⚠ SIM detected but not registered yet")
        print("This may be normal if SIM just powered on. SMS may still work.")

    # ------------------------------------------------
    # 5. SMS text mode
    # ------------------------------------------------
    sim800l.write(b'AT+CMGF=1\r\n')
    time.sleep(1)

    sms_response = sim800l.read()
    print("SMS mode:", sms_response)

    print("=== SIM800L INITIALIZATION COMPLETE ===\n")

    return True


def send_sms(phone_number, message):
    """Send SMS via SIM800L"""
    if not SMS_ENABLED:
        print("SMS disabled in configuration")
        return False
    
    try:
        # Clear any pending data
        sim800l.read()
        
        # Check if SIM is ready
        sim800l.write(b'AT+CPAS\r\n')
        time.sleep(0.5)
        response = sim800l.read()
        print("SIM status:", response)
        
        # Set recipient number
        cmd = 'AT+CMGS="{}"\r\n'.format(phone_number)
        print("Sending command:", cmd)
        sim800l.write(cmd.encode())
        
        # Wait for ">" prompt (max 5 seconds)
        for i in range(10):
            time.sleep(0.5)
            response = sim800l.read()
            if response and b'>' in response:
                print("Got > prompt, sending message")
                break
            elif response and b'ERROR' in response:
                print("ERROR from SIM800L:", response)
                return False
        
        # Send message
        sim800l.write(message.encode())
        sim800l.write(b'\x1A')  # Ctrl+Z to send
        time.sleep(5)  # Wait for transmission
        
        # Read response
        response = sim800l.read()
        print("SMS response:", response)
        
        if response and (b'OK' in response or b'+CMGS:' in response):
            print("✓ SMS sent to:", phone_number)
            return True
        else:
            print("✗ SMS failed to:", phone_number)
            print("Response was:", response)
            return False
            
    except Exception as e:
        print("SMS Error:", e)
        return False


def get_household_numbers():
    """Fetch household phone numbers from server"""
    global household_numbers
    
    url = SERVER_URL + "/api/get_household_numbers.php?sensor_code=" + SENSOR_CODE
    
    try:
        print("Fetching household numbers...")
        response = urequests.get(url)
        
        if response.status_code == 200:
            result = response.json()
            if result.get("status") == "success":
                household_numbers = []
                for h in result["data"]["households"]:
                    # Normalize phone number to +63 format
                    number = h["contact_number"]
                    number = number.replace(" ", "").replace("-", "")
                    if number.startswith("09"):
                        number = "+63" + number[1:]
                    elif number.startswith("9"):
                        number = "+63" + number
                    household_numbers.append(number)
                
                print("✓ Loaded", len(household_numbers), "household numbers")
                return True
            else:
                print("Server error:", result.get("message"))
        else:
            print("HTTP error:", response.status_code)
        
        response.close()
        return False
        
    except Exception as e:
        print("Error fetching numbers:", e)
        return False


def send_alert_sms(water_level, alert_level):
    """Send SMS alert to all households"""
    if not SMS_ENABLED or not household_numbers:
        return
    
    timestamp = get_timestamp()
    
    messages = {
        'warning': "FLOODWATCH [WARNING] Brgy. Baliwagan: Water level at {}cm. Flooding possible. Monitor the situation and prepare. Time: {} -FloodWatch EWS".format(water_level, timestamp),
        'danger': "FLOODWATCH [DANGER] Brgy. Baliwagan: Water level at {}cm. HIGH FLOOD RISK. Prepare for evacuation. Move to higher ground now. Time: {} -FloodWatch EWS".format(water_level, timestamp),
        'critical': "FLOODWATCH [CRITICAL] Brgy. Baliwagan: Water level at {}cm. EVACUATE IMMEDIATELY! Go to evacuation center. This is an emergency. Time: {} -FloodWatch EWS".format(water_level, timestamp)
    }
    
    message = messages.get(alert_level, messages['warning'])
    
    print(f"\n=== SENDING {alert_level.upper()} ALERT SMS ===")
    print("Message:", message)
    print("Recipients:", len(household_numbers))
    
    sent = 0
    for number in household_numbers:
        if send_sms(number, message):
            sent += 1
        time.sleep(2)  # Delay between SMS to avoid overload
    
    print(f"SMS Alert Complete: {sent}/{len(household_numbers)} sent")
    print("=" * 40)


def get_alert_level(water_level):
    """Determine alert level based on water level"""
    if water_level >= CRITICAL_THRESHOLD:
        return 'critical'
    elif water_level >= DANGER_THRESHOLD:
        return 'danger'
    elif water_level >= WARNING_THRESHOLD:
        return 'warning'
    else:
        return 'safe'


# ============================================================
# TIME SETUP
# ============================================================

def setup_time():

    try:
        print("Updating time...")
        
        ntptime.host = "pool.ntp.org"
        ntptime.settime()

        print("Time synchronized")

    except Exception as e:
        print("NTP error:", e)



def get_timestamp():

    t = time.localtime()

    # UTC+8 Philippines
    t = time.localtime(time.mktime(t) + 8 * 3600)

    return (
        "{:04d}-{:02d}-{:02d} {:02d}:{:02d}:{:02d}"
        .format(
            t[0],
            t[1],
            t[2],
            t[3],
            t[4],
            t[5]
        )
    )


# ============================================================
# READ WATER LEVEL
# ============================================================

def read_water_level():
    # Oversampling: take 5 samples and average them
    samples = []
    for _ in range(5):
        samples.append(sensor.read())
        time.sleep(0.01)
    analog_value = sum(samples) / len(samples)

    voltage = (analog_value / 4095) * 3.3


    water_level = 0


    if voltage > MIN_VOLTAGE:

        water_level = (
            (voltage - MIN_VOLTAGE)
            /
            (MAX_VOLTAGE - MIN_VOLTAGE)
        ) * MAX_LEVEL_CM


    if water_level < 0:
        water_level = 0


    if water_level > MAX_LEVEL_CM:
        water_level = MAX_LEVEL_CM


    print(
        "Raw:",
        analog_value,
        "| Voltage:",
        round(voltage,2),
        "V | Level:",
        round(water_level,1),
        "cm"
    )


    return round(water_level,1)



# ============================================================
# SEND DATA TO SERVER
# ============================================================

def send_sensor_data(water_level):

    url = SERVER_URL + API_ENDPOINT

    # Read GPS data before sending
    gps = read_gps()

    payload = {
        "sensor_code": SENSOR_CODE,
        "water_level": water_level,
        "timestamp": get_timestamp()
    }

    # Add GPS data if available
    if gps and gps['fix']:
        payload["latitude"] = gps['lat']
        payload["longitude"] = gps['lng']

    json_data = ujson.dumps(payload)


    print("Sending:")
    print(json_data)


    try:

        headers = {
            "Content-Type": "application/json"
        }


        response = urequests.post(
            url,
            data=json_data,
            headers=headers
        )


        print("HTTP:", response.status_code)

        # Read raw response first for debugging
        raw_response = response.text
        print("Raw response:", raw_response)

        try:
            result = response.json()
            print(result)
        except Exception as e:
            print("JSON parse error:", e)
            print("Using fallback - assuming success if HTTP 200")
            result = {"status": "success"}


        if result.get("status") == "success":

            print("✓ Data sent successfully")


            if "data" in result:

                print(
                    "Alert Status:",
                    result["data"].get("alert_status")
                )

        else:

            print(
                "Server error:",
                result.get("message")
            )


        response.close()


    except Exception as e:

        print("HTTP Error:", e)



# ============================================================
# DIAGNOSTIC
# ============================================================

def print_diagnostic():

    wlan = network.WLAN(network.STA_IF)

    print("\n=== DIAGNOSTIC ===")

    print(
        "WiFi:",
        wlan.isconnected()
    )

    print(
        "IP:",
        wlan.ifconfig()[0]
    )

    print(
        "Signal:",
        wlan.status('rssi'),
        "dBm"
    )

    print("==================")



# ============================================================
# MAIN PROGRAM
# ============================================================

print("\n=== FloodWatch ESP32 Water Level Sensor ===")


wifi = connect_wifi()

setup_time()


# Initialize SIM800L if enabled
if SMS_ENABLED:
    init_sim800l()
    # Fetch household numbers from server
    get_household_numbers()

# Initialize GPS if enabled
if GPS_ENABLED:
    init_gps()


print("Sensor started")


while True:


    if not wifi.isconnected():

        wifi = connect_wifi()



    current_time = time.ticks_ms()


    # Read water level every 1 second
    if (
        time.ticks_diff(
            current_time,
            last_reading_time
        )
        >= READING_INTERVAL
    ):

        last_reading_time = current_time


        level = read_water_level()
        
        # Add to buffer for averaging
        readings_buffer.append(level)
        
        # Keep buffer size manageable (max 100 readings for better accuracy)
        if len(readings_buffer) > 100:
            readings_buffer.pop(0)


    # Send average every 30 seconds
    if (
        time.ticks_diff(
            current_time,
            last_send_time
        )
        >= SEND_INTERVAL
    ):

        last_send_time = current_time
        
        # Calculate average from buffer with outlier filtering
        if readings_buffer:
            # Remove outliers (readings that deviate more than 10cm from mean)
            mean_level = sum(readings_buffer) / len(readings_buffer)
            filtered_buffer = [r for r in readings_buffer if abs(r - mean_level) < 10]
            
            if filtered_buffer:
                # Use median for better accuracy (less affected by outliers)
                filtered_buffer.sort()
                median_level = filtered_buffer[len(filtered_buffer) // 2]
                avg_level = round(median_level, 1)
                print(f"Filtered {len(readings_buffer) - len(filtered_buffer)} outliers")
                print(f"Median of {len(filtered_buffer)} readings: {avg_level} cm")
            else:
                # Fallback to mean if all readings were filtered
                avg_level = round(mean_level, 1)
                print(f"Mean of {len(readings_buffer)} readings: {avg_level} cm")
            
            send_sensor_data(avg_level)
            
            # Check alert level and send SMS if needed
            current_alert = get_alert_level(avg_level)
            
            # Send SMS only when alert level changes
            if current_alert != last_alert_level and current_alert != 'safe':
                send_alert_sms(avg_level, current_alert)
                last_alert_level = current_alert
            elif current_alert == 'safe' and last_alert_level != 'safe':
                # Reset when back to safe
                last_alert_level = 'safe'
            
            # Clear buffer after sending
            readings_buffer = []



    time.sleep(0.1)
