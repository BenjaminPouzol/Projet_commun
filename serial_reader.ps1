# serial_reader.ps1 - Lit COM3 et met a jour la BDD via PHP
# Usage : powershell -ExecutionPolicy Bypass -File serial_reader.ps1

$COM_PORT  = "COM3"
$BAUD      = 9600
$API_BASE  = "http://127.0.0.1/Projet_commun/php/api_update_machine.php"
$TEAM      = "G9E"
$MACHINE   = 1
$SEUIL_ADC        = 200
$DEBOUNCE_ENTREE  = 3    # lectures pour passer LIBRE -> OCCUPEE (rapide)
$DEBOUNCE_SORTIE  = 20   # lectures pour passer OCCUPEE -> LIBRE (lent, evite faux LIBRE si trop proche)

$port = New-Object System.IO.Ports.SerialPort $COM_PORT, $BAUD, "None", 8, "One"
$port.ReadTimeout = 3000

try {
    $port.Open()
} catch {
    Write-Host "ERREUR ouverture port : $_"
    exit 1
}

Write-Host "Port $COM_PORT ouvert. Seuil ADC=$SEUIL_ADC. Approche-toi du capteur..."

$compteurOccupee = 0
$compteurLibre   = 0
$dernierEtat     = $null

while ($true) {
    try {
        $line = $port.ReadLine().Trim()
        if ($line -eq "") { continue }

        $adc  = -1
        $etat = $null

        if ($line -match 'ADC:(\d+)') {
            $adc = [int]$Matches[1]
            if ($adc -ge $SEUIL_ADC) { $etat = "OCCUPEE" } else { $etat = "LIBRE" }
        } elseif ($line -match '^PROXIMITE:(\d+)$') {
            $adc = [int]$Matches[1]
            if ($adc -ge 500) { $etat = "OCCUPEE" } else { $etat = "LIBRE" }
        }

        if ($null -eq $etat) { continue }

        Write-Host "ADC=$adc -> $etat"

        if ($etat -eq "OCCUPEE") {
            $compteurOccupee++
            $compteurLibre = 0
        } else {
            $compteurLibre++
            $compteurOccupee = 0
        }

        # Entree OCCUPEE : 3 lectures consecutives suffisent
        if ($etat -eq "OCCUPEE" -and $compteurOccupee -eq $DEBOUNCE_ENTREE -and $dernierEtat -ne "OCCUPEE") {
            $etatFinal = "OCCUPEE"
        # Sortie vers LIBRE : 20 lectures consecutives requises
        } elseif ($etat -eq "LIBRE" -and $compteurLibre -eq $DEBOUNCE_SORTIE -and $dernierEtat -ne "LIBRE") {
            $etatFinal = "LIBRE"
        } else {
            continue
        }

        $dernierEtat = $etatFinal

        $dernierEtat = $etatFinal
        $url = $API_BASE + "?etat=" + $etatFinal + "&valeur=" + $adc + "&team=" + $TEAM + "&machine=" + $MACHINE

        Write-Host "Appel API : $url"
        try {
            $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3
            Write-Host ("  -> " + $resp.Content)
        } catch {
            Write-Host "  -> ERREUR API : $_"
        }

    } catch [System.TimeoutException] {
    } catch {
        Write-Host "Erreur lecture : $_"
        Start-Sleep -Seconds 2
    }
}

$port.Close()
