# ESP32 Water Level Sensor Setup Guide

This guide will help you connect your ESP32 water level sensors to the FloodWatch system.

**Available firmware options:**
- **Arduino C++** (`water_level_sensor.ino`) - For Arduino IDE
- **MicroPython** (`water_level_sensor.py`) - For MicroPython firmware

## Prerequisites

- ESP32 development board
- Water level sensor (analog or ultrasonic)
- WiFi network access
- **For Arduino:** Arduino IDE with ESP32 support
- **For MicroPython:** MicroPython firmware flashed on ESP32
- FloodWatch system running on your server
- **For SMS Alerts:** SIM800L GSM module with SIM card and antenna

## Step 1: Choose Your Firmware Option

### Option A: Arduino C++ (Traditional)

In Arduino IDE, install these libraries via Library Manager:
- **WiFi** (built-in)
- **HTTPClient** (built-in)
- **ArduinoJson** by Benoit Blanchon
- **NTPClient** by Fabrice Weinberg

### Option B: MicroPython (Recommended for easier development)

1. Flash MicroPython firmware to your ESP32:
   - Download from https://micropython.org/download/esp32/
   - Use esptool.py to flash: `esptool.py --chip esp32 --port COM3 write_flash -z 0x1000 esp32-20210902-v1.17.bin`
   - Replace COM3 with your ESP32's port

2. Upload code using:
   - **Thonny IDE** (recommended - https://thonny.org)
   - **ampy** command line tool
   - **rshell** command line tool
   - WebREPL (wireless upload)

## Step 2: Hardware Setup

### SIM800L GSM Module Wiring (for SMS Alerts)

Connect the SIM800L module to your ESP32 as follows:

| SIM800L Pin | ESP32 Pin | Description |
|-------------|-----------|-------------|
| VCC | 5V or 3.3V* | Power (check your module - some require 5V) |
| GND | GND | Ground |
| TX | GPIO 16 | ESP32 RX (configured in firmware) |
| RX | GPIO 17 | ESP32 TX (configured in firmware) |

**Important Notes:**
- SIM800L can draw up to 2A during transmission - use an external power supply if possible
- Add a 1000µF capacitor between VCC and GND to stabilize power
- Ensure the SIM card has credit/plan for sending SMS
- The antenna must be properly connected for GSM signal
- Allow 30-60 seconds after power-on for SIM800L to register on the network

### Water Level Sensor Wiring

| Sensor Pin | ESP32 Pin |
|------------|-----------|
| VCC | 3.3V |
| GND | GND |
| Signal | GPIO 34 (configurable in firmware) |

## Step 3: Configure ESP32 Firmware

### For Arduino C++ (`water_level_sensor.ino`):

```cpp
// WiFi Credentials
const char* WIFI_SSID = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

// FloodWatch Server Configuration
const char* SERVER_URL = "http://192.168.1.100/floodwatch"; // Your server IP
const char* API_ENDPOINT = "/api/sensor_data.php";

// Sensor Configuration
const String SENSOR_CODE = "SENSOR-01";  // Must match database sensor_code
const int SENSOR_PIN = 34;               // Analog pin for water level sensor
const int READING_INTERVAL = 60000;      // Send reading every 60 seconds
```

### For MicroPython (`water_level_sensor.py`):

```python
# WiFi Credentials
WIFI_SSID = "YOUR_WIFI_SSID"
WIFI_PASSWORD = "YOUR_WIFI_PASSWORD"

# FloodWatch Server Configuration
SERVER_URL = "http://192.168.1.100/floodwatch"
API_ENDPOINT = "/api/sensor_data.php"

# Sensor Configuration
SENSOR_CODE = "SENSOR-01"
SENSOR_PIN = 34
READING_INTERVAL = 30000  # milliseconds (30 seconds)

# SIM800L GSM Module Configuration
SIM800L_TX_PIN = 17  # ESP32 GPIO connected to SIM800L RX
SIM800L_RX_PIN = 16  # ESP32 GPIO connected to SIM800L TX
SIM800L_BAUDRATE = 9600
SMS_ENABLED = True  # Set to False to disable SMS alerts

# Alert Thresholds (cm)
WARNING_THRESHOLD = 70
DANGER_THRESHOLD = 100
CRITICAL_THRESHOLD = 130
```

## Step 4: Calibrate Your Water Level Sensor

Update the calibration values based on your specific sensor:

**For Arduino C++:**
```cpp
const float MIN_VOLTAGE = 0.5;   // Voltage at 0cm (measure with multimeter)
const float MAX_VOLTAGE = 3.3;   // Voltage at max level
const float MAX_LEVEL_CM = 200;  // Maximum measurable level in cm
```

**For MicroPython:**
```python
MIN_VOLTAGE = 0.5
MAX_VOLTAGE = 3.3
MAX_LEVEL_CM = 200
```

**To calibrate:**
1. Place sensor at known 0cm level
2. Read the voltage output (use Serial Monitor)
3. Set MIN_VOLTAGE to this value
4. Place sensor at known max level
5. Read the voltage output
6. Set MAX_VOLTAGE to this value
7. Set MAX_LEVEL_CM to your maximum measurable distance

## Step 5: Upload Firmware to ESP32

### For Arduino C++:

1. Connect ESP32 to your computer via USB
2. In Arduino IDE, select your board: Tools > Board > ESP32 Arduino > [Your ESP32 Model]
3. Select the correct COM port
4. Click Upload button
5. Open Serial Monitor (115200 baud) to see output

### For MicroPython (using Thonny IDE):

1. Connect ESP32 to your computer via USB
2. Open Thonny IDE
3. Go to Tools > Options > Interpreter
4. Select "MicroPython (ESP32)"
5. Select the correct COM port
6. Click "Run" or press F5 to upload and run the code
7. The code will be uploaded to the ESP32 and start running immediately

**Alternative MicroPython upload methods:**

**Using ampy:**
```bash
ampy --port COM3 put water_level_sensor.py
ampy --port COM3 run water_level_sensor.py
```

**Using rshell:**
```bash
rshell -p COM3
> cp water_level_sensor.py /main.py
> repl
```

**Note:** If you save as `main.py`, it will run automatically on boot.

## Step 6: Verify Database Sensor Configuration

Ensure your sensors are properly configured in the database:

1. Go to phpMyAdmin: http://localhost/phpmyadmin
2. Select `floodwatch_db` database
3. Check `sensors` table
4. Verify each sensor has:
   - Correct `sensor_code` (must match ESP32 firmware)
   - `status` = 'online'
   - Valid `purok_id`

Example sensor setup:
```sql
UPDATE sensors SET sensor_code = 'SENSOR-01', status = 'online' WHERE id = 1;
UPDATE sensors SET sensor_code = 'SENSOR-02', status = 'online' WHERE id = 2;
UPDATE sensors SET sensor_code = 'SENSOR-03', status = 'online' WHERE id = 3;
UPDATE sensors SET sensor_code = 'SENSOR-04', status = 'online' WHERE id = 4;
```

## Step 7: Test the Connection

1. Power on your ESP32
2. Watch Serial Monitor (Arduino) or Shell (Thonny) for connection messages
3. You should see:
   - WiFi connection successful
   - IP address assigned
   - Sensor readings being sent
   - Server responses

**Expected Arduino Serial Monitor output:**
```
=== FloodWatch ESP32 Water Level Sensor ===
Initializing...
Connecting to WiFi: YourNetwork
.........
✓ WiFi connected!
IP address: 192.168.1.50
Setup complete. Starting sensor readings...
Raw: 2048 | Voltage: 1.65V | Water Level: 75.5 cm
Sending data to server: http://192.168.1.100/floodwatch/api/sensor_data.php
Payload: {"sensor_code":"SENSOR-01","water_level":75.5,"timestamp":"2026-07-14 11:30:00"}
HTTP Response code: 200
Response: {"status":"success","message":"Sensor reading recorded successfully",...}
✓ Data sent successfully!
Alert Status: warning
```

**Expected MicroPython/Thonny output:**
```
=== FloodWatch ESP32 Water Level Sensor ===
Connecting to WiFi: YOTC-D71737
....
WiFi connected!
IP: 192.168.1.50
Updating time...
Time synchronized
Initializing SIM800L...
✓ SIM800L detected
Fetching household numbers...
✓ Loaded 5 household numbers
Sensor started
Raw: 2048 | Voltage: 1.65 V | Level: 75.5 cm
Sending: {"sensor_code":"SENSOR-01","water_level":75.5,"timestamp":"2026-07-14 11:30:00"}
HTTP: 200
{'status': 'success', 'message': 'Sensor reading recorded successfully', ...}
✓ Data sent successfully
Alert Status: warning

=== SENDING WARNING ALERT SMS ===
Message: FLOODWATCH [WARNING] Brgy. Baliwagan: Water level at 75.5cm...
Recipients: 5
✓ SMS sent to: +639123456789
✓ SMS sent to: +639987654321
...
SMS Alert Complete: 5/5 sent
========================================
```

## Step 8: Monitor Real-Time Data

1. Open FloodWatch in your browser
2. Navigate to **Sensor Monitor** (formerly Simulator)
3. You should see live readings from your ESP32 devices
4. Data updates automatically as ESP32 sends readings

## API Endpoint Details

**URL:** `http://your-server/floodwatch/api/sensor_data.php`

**Method:** POST

**Content-Type:** application/json

**Required Fields:**
- `sensor_code` - String, must match database sensor_code
- `water_level` - Float, water level in cm (0-300)

**Optional Fields:**
- `timestamp` - String, datetime in format "YYYY-MM-DD HH:MM:SS"

**Example Request:**
```json
{
  "sensor_code": "SENSOR-01",
  "water_level": 75.5,
  "timestamp": "2026-07-14 11:30:00"
}
```

**Success Response:**
```json
{
  "status": "success",
  "message": "Sensor reading recorded successfully",
  "data": {
    "sensor_code": "SENSOR-01",
    "water_level": 75.5,
    "alert_status": "warning",
    "timestamp": "2026-07-14 11:30:00",
    "reading_id": 123
  }
}
```

**Error Responses:**
- `400` - Missing required fields or invalid water level
- `403` - Sensor is not online
- `404` - Sensor code not found in database
- `405` - Wrong HTTP method (must be POST)

## Troubleshooting

### ESP32 won't connect to WiFi
- Check WiFi credentials are correct
- Ensure ESP32 is within WiFi range
- Try different WiFi channel (2.4GHz only)

### Server returns 404 error
- Verify SERVER_URL is correct
- Check that api/sensor_data.php exists
- Ensure XAMPP Apache is running

### Server returns 404 - Sensor not found
- Verify sensor_code matches database exactly
- Check sensor status is 'online' in database
- Use phpMyAdmin to verify sensor exists

### Server returns 403 - Sensor not online
- Update sensor status in database: `UPDATE sensors SET status='online' WHERE id=X;`
- Or use the Sensors page in FloodWatch admin

### No SMS alerts received
- Check includes/sms.php configuration (server-side SMS)
- For SIM800L: Verify module is detected during initialization
- For SIM800L: Check SIM card has credit/plan
- For SIM800L: Ensure antenna is connected and has GSM signal
- For SIM800L: Verify household phone numbers are loaded from server
- Ensure households have phone numbers in database
- Check SMS log in Sensor Monitor after simulation

### Sensor readings not updating
- Check Serial Monitor for ESP32 errors
- Verify network connectivity
- Check server error logs in XAMPP
- Ensure API endpoint is accessible

## Multiple ESP32 Setup

For multiple sensors (one per purok):

**For Arduino C++:**
1. Create separate firmware for each ESP32
2. Change `SENSOR_CODE` for each:
   - Purok 1: `SENSOR-01`
   - Purok 2: `SENSOR-02`
   - Purok 3: `SENSOR-03`
   - Purok 4: `SENSOR-04`
3. Upload to respective ESP32 devices
4. Each will send data for its assigned purok

**For MicroPython:**
1. Create separate `.py` files for each ESP32
2. Change `SENSOR_CODE` for each:
   - Purok 1: `SENSOR-01`
   - Purok 2: `SENSOR-02`
   - Purok 3: `SENSOR-03`
   - Purok 4: `SENSOR-04`
3. Upload as `main.py` to each ESP32 for auto-start
4. Each will send data for its assigned purok

## Security Notes

- The API uses sensor_code for authentication
- Keep your sensor codes secure
- Consider adding API key authentication for production
- Use HTTPS in production environments
- Restrict API access to local network only

## Production Deployment

For production use:

1. **Use HTTPS:** Update SERVER_URL to https://
2. **Add API Key Authentication:** Modify api/sensor_data.php to require API key
3. **Add Rate Limiting:** Prevent spam requests
4. **Add Error Logging:** Log failed requests for debugging
5. **Monitor Device Health:** Add heartbeat/ping mechanism
6. **Backup Configuration:** Keep firmware and settings backed up

## Support

For issues or questions:
- Check Serial Monitor output
- Review server error logs: `C:\xampp\apache\logs\error.log`
- Verify database configuration
- Test API endpoint manually using curl or Postman
