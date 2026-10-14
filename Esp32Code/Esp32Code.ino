#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

// =========================
// Wi-Fi
// =========================
const char* ssid = "ZTE_2.4G_GCAY3S";
const char* password = "42zdEe97";

// =========================
// HOSTINGER SERVER
// =========================
const char* serverName =
    "https://wheat-ibis-447907.hostingersite.com/insert.php";

// =========================
// PINS
// =========================
const int flowPin = 4;
const int relayPin = 5;

// =========================
// VARIABLES
// =========================
volatile int pulseCount = 0;

float flowRate = 0.0;

String valveStatus = "closed";

// =========================
// FLOW SENSOR INTERRUPT
// =========================
void IRAM_ATTR pulseCounter()
{
    pulseCount++;
}

// =========================
// VALVE CONTROL
// =========================
void applyValveCommand(const String& command)
{
    if (command == "open")
    {
        digitalWrite(relayPin, LOW);

        valveStatus = "open";

        Serial.println("Valve OPEN (server command)");
    }
    else if (command == "closed")
    {
        digitalWrite(relayPin, HIGH);

        valveStatus = "closed";

        Serial.println("Valve CLOSED (server command)");
    }
}

// =========================
// PARSE VALVE COMMAND
// =========================
String parseValveCommand(const String& response)
{
    int start = response.indexOf("valve_command=");

    if (start < 0)
    {
        return "";
    }

    start += 14;

    int end = response.indexOf('\n', start);

    String command;

    if (end > start)
    {
        command = response.substring(start, end);
    }
    else
    {
        command = response.substring(start);
    }

    command.trim();

    return command;
}

// =========================
// SETUP
// =========================
void setup()
{
    Serial.begin(115200);

    // -------------------------
    // Flow Sensor
    // -------------------------
    pinMode(flowPin, INPUT_PULLUP);

    attachInterrupt(
        digitalPinToInterrupt(flowPin),
        pulseCounter,
        FALLING
    );

    // -------------------------
    // Relay
    // -------------------------
    pinMode(relayPin, OUTPUT);

    // Relay OFF / valve closed
    digitalWrite(relayPin, HIGH);

    valveStatus = "closed";

    // -------------------------
    // Wi-Fi
    // -------------------------
    WiFi.begin(ssid, password);

    Serial.print("Connecting to Wi-Fi");

    while (WiFi.status() != WL_CONNECTED)
    {
        delay(500);
        Serial.print(".");
    }

    Serial.println();
    Serial.println("Wi-Fi Connected");

    Serial.print("ESP32 IP Address: ");
    Serial.println(WiFi.localIP());
}

// =========================
// MAIN LOOP
// =========================
void loop()
{
    // Reset pulse counter
    pulseCount = 0;

    // Measure flow for 1 second
    delay(1000);

    // YF-S201 approximate conversion
    flowRate = pulseCount / 7.5;

    Serial.print("Flow Rate: ");
    Serial.print(flowRate, 2);
    Serial.println(" L/min");

    // =========================
    // CHECK WI-FI
    // =========================
    if (WiFi.status() == WL_CONNECTED)
    {
        WiFiClientSecure client;

        // Allows HTTPS connection for testing.
        client.setInsecure();

        HTTPClient http;

        Serial.println("Connecting to Hostinger...");

        if (http.begin(client, serverName))
        {
            http.addHeader(
                "Content-Type",
                "application/x-www-form-urlencoded"
            );

            // -------------------------
            // Data sent to PHP
            // -------------------------
            String httpRequestData =
                "flow_rate=" +
                String(flowRate, 2) +
                "&valve_status=" +
                valveStatus;

            Serial.print("Sending: ");
            Serial.println(httpRequestData);

            // -------------------------
            // POST DATA
            // -------------------------
            int httpResponseCode =
                http.POST(httpRequestData);

            // -------------------------
            // SERVER RESPONSE
            // -------------------------
            if (httpResponseCode > 0)
            {
                Serial.print("HTTP Response Code: ");
                Serial.println(httpResponseCode);

                String response = http.getString();

                Serial.println("Server Response:");
                Serial.println(response);

                // -------------------------
                // Get valve command
                // -------------------------
                String command =
                    parseValveCommand(response);

                if (command == "open" ||
                    command == "closed")
                {
                    applyValveCommand(command);
                }
                else
                {
                    Serial.println(
                        "No valid valve command received."
                    );
                }
            }
            else
            {
                Serial.print("HTTP Error: ");
                Serial.println(httpResponseCode);
            }

            http.end();
        }
        else
        {
            Serial.println(
                "Unable to connect to Hostinger server."
            );
        }
    }
    else
    {
        Serial.println(
            "Wi-Fi disconnected. Reconnecting..."
        );

        WiFi.disconnect();
        WiFi.begin(ssid, password);
    }

    // Wait before next reading
    delay(4000);
}