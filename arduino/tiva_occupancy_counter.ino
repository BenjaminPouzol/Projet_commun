/*
 * Tiva C (TM4C123) – Capteur de proximité machine
 * IDE : Energia (compatible Arduino API)
 *
 * Câblage :
 *   Capteur IR/Analogique → PA0 (pin A0)
 *   UART0 → USB/FTDI → PC (COM22, 9600 baud)
 *
 * Protocole série : "PROXIMITE:XXXX\n"  toutes les 100 ms
 *   XXXX = valeur ADC brute (0–4095, ADC 12 bits Tiva)
 *   >= 500 → quelqu'un devant la machine (OCCUPÉE)
 *   <  500 → personne (LIBRE)
 */

const int CAPTEUR_PIN = A0;
const int INTERVAL_MS = 100;   // Envoi toutes les 100 ms

void setup() {
  Serial.begin(9600);
  pinMode(CAPTEUR_PIN, INPUT);
  delay(200);
}

void loop() {
  Serial.print("PROXIMITE:");
  Serial.println(analogRead(CAPTEUR_PIN));
  delay(INTERVAL_MS);
}
