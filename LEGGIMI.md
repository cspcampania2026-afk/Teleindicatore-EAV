> Aggiornamento: le regole e le funzioni correnti sono descritte in **MONITOR_RESOCONTO.md** (singolo avviso di ritardo, niente cambi binario automatici, schede, PDF e paginazione).

# Teleindicatore CSP · Tre circuiti

Per conservare i dati senza un disco Render, leggere **DATABASE_GRATUITO.md** e impostare `DATABASE_URL`.

Account personali: leggere **GESTIONE_UTENTI.md** per primo accesso, ruoli, creazione utenti e persistenza.

Prima versione implementata basata sui file caricati. Il formato del tabellone, i colori delle linee e lo scorrimento delle informazioni ufficiali di 180 secondi sono mantenuti. I tempi delle fermate Flegree non vengono più ricavati da offset inventati: si mostrano le informazioni effettivamente fornite dal teleindicatore EAV. Il logo EAV è stato rimosso. La schermata interna è stata ricostruita dalla foto di riferimento: menu linee/stazioni a sinistra, ricerca corsa, tabella operativa, binari e pulsante rosso di soppressione. È inclusa una anteprima con dati simulati e nessuna connessione reale.

## Accessi

- `index.html`: pubblico, sola lettura.
- `schermi.php`: schermi in stazione, stesso tabellone con selettori disponibili anche dopo l'avvio e pulsante Cambia stazione. Memorizza stazione, linea e verso sul dispositivo. `schermi.php?station=801&line=F9&tipo=P` apre una configurazione prefissata; `?setup=1` riapre la configurazione. Per un chiosco vero usare anche la modalità kiosk del browser.
- `admin.php`: circuito interno, login richiesto; tabella Arrivo/Partenza teorico ed effettivo, gestione binari, note interne, soppressioni per tratta o intero percorso, composizione avvisi, stato monitor e registro operazioni.

Le tre viste condividono lo stesso stato sul server. Il pubblico non riceve l'elenco delle corse rimosse e non può usare i comandi admin. Il solo parametro URL non concede privilegi.

## Gestione delle corse

- **Rimuovi dal tabellone**: nasconde una corsa dalla stazione e dal verso selezionati, sul pubblico e sugli schermi. Non equivale a una soppressione del servizio EAV.
- **Sopprimi**: mantiene la corsa visibile con testo e indicatori grigi, più il riquadro `SOPPRESSO` rosso scuro (#943b45). Ha precedenza sullo scorrimento delle fermate anche nelle Flegree. Se EAV la rimuove, resta visibile fino a tre ore dopo l'orario previsto.
- **Ripristina**: elimina la modifica manuale e torna al dato EAV. Una soppressione presente nel dato EAV non viene cancellata da questo comando.

Le modifiche sono legate alla data, stazione, verso, numero, destinazione e orario: non influenzano altre stazioni o la corsa del giorno successivo. Scadono a mezzanotte, ora italiana. Un cambio di orario/destinazione da parte di EAV crea una nuova identità e può richiedere di riapplicare la modifica. La gestione locale resta disponibile dal pulsante ✎. Le soppressioni estese descritte sotto sono indipendenti dalle rimozioni locali.

## Area interna ricostruita dalla foto

- Monitor Arrivi, Monitor Partenze e Gestione corse con arrivo e partenza affiancati.
- Menu linee, stazioni e ricerca per numero o destinazione. A Montesanto le corse 5xxxx e 9xxxx sono distinte nei filtri L5/L9. Le numerazioni non riconoscibili non vengono nascoste automaticamente.
- Orari teorici separati tra le due sorgenti. I feed vengono uniti solo quando il numero compare una sola volta in ciascun feed: i numeri duplicati restano righe distinte.
- Orari **effettivi**: la sorgente del proxy caricato non espone timestamp effettivi certificati. Sono lasciati vuoti, senza usare il ritardo per inventarli; gli admin possono registrarli con HH:MM:SS dal pulsante ✎. La tabella li identifica come **MANUALE**. Un’integrazione con dati effettivi del sistema operativo EAV richiede una sorgente autorizzata che li fornisca.
- Modifica binari arrivo/partenza e nota interna. Le note sono riservate agli admin e non sono inviate al pubblico né nelle notifiche automatiche. Un campo effettivo o binario manuale svuotato torna alla sorgente disponibile.
- Pulsante rosso **−**: apre la scelta fra singola stazione, tratta da/a e intero percorso della corsa sulla linea selezionata.
- Nella soppressione da A a B, A perde la partenza e B perde l’arrivo; le fermate intermedie perdono entrambi. È possibile scegliere il verso inverso.
- Una regola di soppressione identifica **data + numero corsa + percorso**: viene riconosciuta nelle altre stazioni anche quando l’orario è diverso. Non sopprime tutti i treni della linea. Gli estremi vengono validati sul server, così come l’appartenenza a una linea quando il numero è riconosciuto.
- Una nuova regola per la stessa corsa e lo stesso percorso sostituisce la precedente. Le regole scadono a mezzanotte, ora italiana. Se la sorgente riusa lo stesso numero per più servizi nella stessa giornata e sullo stesso percorso, la regola interessa tutte le occorrenze: senza un identificativo servizio più preciso non è possibile distinguerle automaticamente.
- L’intero percorso usa la lista ordinata dei codici in `percorsi.json`, derivata dall’anagrafica caricata. Include anche codici di impianti o fermate non servite: non indica che tutti siano aperti o che una specifica corsa fermi ovunque. I percorsi vanno verificati prima dell’uso operativo e possono essere aggiornati senza modificare il motore.
- Le soppressioni estese sono condivise con pubblico e schermi. Il registro consente di revocarle; una soppressione già presente nel dato EAV resta valida. Una rimozione locale ha precedenza sulla visualizzazione di una soppressione estesa.
- Se la corsa scompare dalla sorgente, viene mantenuta per massimo tre ore dall’orario teorico solo nelle stazioni dove esiste un precedente snapshot del monitor. Non vengono inventati orari per stazioni non ancora osservate.
- Gli avvisi Telegram per le regole includono la tratta o il percorso. Gli snapshot noti vengono aggiornati per limitare la ripetizione della stessa modifica manuale nelle successive letture.

Gli effettivi registrati a mano e i binari si riferiscono al movimento della stazione selezionata; non vengono scritti nel sistema ufficiale EAV. Le note e le modifiche sono conservate nel database CSP. Le icone della foto di cui non è nota la funzione (per esempio audio/annunci fisici) non sono state collegate a dispositivi di stazione.

Aprire `anteprima_admin.html` dal pacchetto per vedere e provare la schermata **con dati simulati**, senza login, senza modificare EAV e senza inviare messaggi Telegram. La schermata reale è `admin.php`.

## Aggiornamento L1bis, dati live e Avvisi

- **L1bis · Napoli – Torre Annunziata** compare subito sotto **L1 · Napoli – Sorrento**, nei menu interni, pubblici, degli schermi e nella legenda. Mantiene il codice interno TORRE per compatibilità con le regole già salvate.
- La stazione 27 è sempre visualizzata come **San Giorgio a Cremano**, anche se la sorgente usa minuscole o abbreviazioni.
- Pubblico, schermi e quadro operativo interrogano il server ogni **10 secondi**. Il server riusa una risposta EAV per massimo 5 secondi, e riaggiorna periodicamente i parametri AJAX dalla pagina ufficiale della stazione. L'ora mostrata è quella dell'ultima lettura della sorgente, nel fuso Europe/Rome.
- Si conserva l'ordine live restituito da EAV, senza riordinare le corse a partire dalla mezzanotte. EAV può includere in fondo alla lista le corse del mattino successivo: restano dopo quelle attuali. Gli orari degli esempi nei test sono dati catturati per verificare il parser, non orari da usare nel servizio.
- La sorgente EAV è stata letta direttamente per Napoli Porta Nolana, San Giorgio a Cremano e Montesanto. Sono stati verificati campi, binari, ritardi, parametri della pagina e ordine delle righe. In caso di errore del collegamento la pagina segnala dati non aggiornati e riprova.
- Il selettore degli schermi resta visibile; l'ultima selezione viene memorizzata nel dispositivo. Il pulsante **Cambia stazione** riapre la configurazione.
- Gli admin pubblicano **Avvisi** per tutte le stazioni o una sola stazione, con durata da 1 ora a 7 giorni. Pubblico e schermi li aggiornano automaticamente. Gli avvisi possono essere ritirati e scadono automaticamente.
- Telegram: **Simula senza inviare**, **Invia prova [PROVA]**, invio normale, verifica bot e lettura ID chat. I messaggi manuali sono limitati a 280 caratteri, quelli automatici a circa 220. La simulazione non contatta Telegram e non accoda messaggi.

Per collegare il bot, seguire **COLLEGARE_TELEGRAM.md**. Caricare l'intero contenuto aggiornato della cartella sul repository, incluse communications.php, avvisi.php, public_notices.js e le risorse dell'admin. Non sostituire soltanto csp_core.php in questo aggiornamento.

## Installazione

1. Caricare tutti i file di questa cartella sullo stesso hosting PHP. Sostituire il vecchio `index.html` e `ws_proxy.php`; non caricare anche copie vecchie con credenziali inserite nel codice.
2. Richiede **PHP 8.1+**, PDO SQLite, DOM/XML, mbstring; cURL consigliato oppure `allow_url_fopen` abilitato. Per h24 il server deve consentire cron o processi CLI persistenti e connessioni HTTPS verso EAV e Telegram.
3. Configurare le variabili d'ambiente per PHP web **e** PHP CLI. Non esporre password e token nell'HTML o in una cartella pubblica.

| Variabile | Valore |
|---|---|
| `CSP_DATA_DIR` | Percorso assoluto, scrivibile da PHP web e CLI, **esterno alla document root**, es. `/var/lib/csp-teleindicatore` |
| `CSP_ADMIN_USER` | Nome utente admin, predefinito `admin` |
| `CSP_ADMIN_PASSWORD_HASH` | Hash generato con `password_hash`, nessuna password predefinita; ha precedenza se presente |
| `CSP_ADMIN_PASSWORD` | In alternativa, password impostata come segreto nel pannello hosting; non inserirla nel codice o nel repository |
| `EAV_TELEGRAM_BOT_TOKEN` | Token del bot già creato |
| `EAV_TELEGRAM_CHANNEL_ID` | ID del canale o `@nomecanale` per avvisi automatici e avvisi manuali al canale |
| `EAV_TELEGRAM_GROUP_ID` | ID del gruppo per avvisi manuali al gruppo |
| `EAV_MONITOR_STATIONS` | ID separati da virgola. Se vuoto monitora tutte le stazioni del CSV |
| `EAV_MONITOR_TYPES` | `P,A` predefinito, oppure solo `P` / solo `A` |
| `EAV_POLL_SECONDS` | Minimo intervallo tra l'inizio di due cicli, predefinito 60, minimo 15 |

Generare l'hash senza inserire una password di esempio nel codice distribuito:

```php
<?php
// Eseguire solo da CLI, passare la password attraverso un input protetto.
echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;
```

Servire l'area admin tramite HTTPS. C'è un account configurabile, con timeout di inattività di un'ora, cookie HttpOnly/SameSite, controlli CSRF e blocco dopo 10 tentativi falliti per IP in 15 minuti. Sono disponibili account nominativi con ruoli e gestione utenti: vedere GESTIONE_UTENTI.md. Database e coda contengono informazioni interne e devono restare privati. Eseguire backup della cartella dati.

## Telegram h24

Il monitor interroga la sorgente del teleindicatore EAV dal server: non dipende da un browser aperto. Deve essere **avviato sul proprio server**: scaricare i file non attiva automaticamente il servizio.

Il bot deve essere membro del gruppo e avere diritto di pubblicare nel canale. Inserire i relativi ID nelle variabili. Il nuovo invio usa le destinazioni configurate, non il vecchio elenco di iscritti in `/tmp`; nessun webhook è necessario per i messaggi in uscita. Il vecchio endpoint `/start` non è incluso in questa versione. Rimuovere o disattivare il vecchio monitor per evitare notifiche duplicate.

Opzione preferita: processo persistente gestito da systemd/Supervisor, con riavvio automatico:

```sh
php /percorso/web/teleindicatore/monitor.php
```

Alternativa con cron ogni minuto (caricare le stesse variabili d'ambiente):

```cron
* * * * * /usr/bin/php /percorso/web/teleindicatore/monitor.php --once >> /percorso/privato/monitor.log 2>&1
```

Un lock impedisce l'esecuzione contemporanea di più monitor. La cartella dati deve essere accessibile dallo stesso utente/gruppo PHP web e CLI: permessi iniziali 0700. SQLite richiede un filesystem locale con locking, non una condivisione di rete.

Il polling delle stazioni è sequenziale: l'intervallo reale può superare 60 secondi con molte stazioni, risposte lente o molti messaggi in coda. Il pannello mostra heartbeat, errori per stazione, durata del ciclo nel dato stato e consegne Telegram. Per una frequenza stretta scegliere prima un gruppo di stazioni e verificare i tempi reali. “H24” significa processo sempre attivo, non disponibilità garantita della sorgente EAV.

Invia messaggi brevi su soppressioni, limitazioni riconoscibili, ritardi e ripristini; i cambi binario automatici sono disattivati; non invia ogni nuova corsa né le lunghe liste di fermate. Il primo ciclo crea una base senza inviare tutte le normali corse; soppressioni presenti sono segnalate. La scomparsa di una corsa dal tabellone non viene interpretata da sola come soppressione. Le operazioni admin sono accodate subito al canale se configurato. Gli avvisi manuali sono testo semplice, massimo 280 caratteri, con anteprima di conferma prima dell'invio.

La coda conserva gli invii falliti, riprova con attesa crescente e tiene separata la conferma Telegram dal semplice accodamento. La doppia pressione su un invio manuale usa un identificativo per evitare duplicati. Un timeout dopo che Telegram ha ricevuto il messaggio può comunque causare un duplicato al tentativo successivo: l'API non offre una conferma idempotente per `sendMessage`. Gli invii consegnati sono conservati 30 giorni, il registro 90 giorni.

## Controlli effettuati e verifiche sul server

Verificati con PHP 8.3 il controllo sintattico di tutti i file, i test offline SQLite di autenticazione, soppressione per tratta/percorso, aggiornamenti manuali, avvisi per stazione/globali, revoca/scadenza, limiti di lunghezza e simulazione senza accodamento. Verificati i parser sulle risposte reali EAV catturate per Napoli Porta Nolana, San Giorgio a Cremano e Montesanto. È stata eseguita anche una lettura live EAV direttamente dal nuovo backend PHP, con numero corsa, binario e ritardo riconosciuti.

Verificati via HTTP locale login, sessione, CSRF, pubblicazione degli avvisi per stazione e simulazione senza invio. Verificati sintassi JavaScript e rendering su dati simulati. La build Docker completa e gli invii reali Telegram richiedono ancora la verifica sul proprio hosting; non sono stati inviati messaggi reali. Un errore temporaneo della sorgente EAV viene segnalato e ritentato.

I test `php tests/regression.php` e `php tests/parser.php` vengono eseguiti anche durante la build Docker. I test offline usano un database temporaneo e disabilitano Telegram.

### Verifica prima dell'uso

1. Login admin: senza credenziali corrette i comandi devono essere rifiutati.
2. Aprire una stessa stazione/verso su pubblico, schermo e admin.
3. Sopprimere una corsa: entro il prossimo aggiornamento di 10 secondi la riga deve essere grigia e mostrare il riquadro rosso.
4. Rimuovere e poi ripristinare una corsa dal menu delle corse rimosse.
5. Avviare il monitor, chiudere tutti i browser e verificare heartbeat e notifiche sul canale.
6. Inviare un avviso dal menu admin al gruppo; verificare lo stato “Consegnato a Telegram”.

Non sono state utilizzate credenziali del server o del bot per inviare messaggi reali durante la preparazione. La verifica live EAV/Telegram restano da completare sul server.

## Aggiornamento 14 ottobre 2026
Admin iniziale: Napoli Porta Nolana (ID 1), salvo una stazione esplicitamente indicata nell’URL. Pubblico e schermi: L4 include navette Torre Annunziata e Poggiomarino; L1bis rimane separata solo nell’admin. Le scelte salvate degli schermi restano disponibili.
Avvisi di servizio più grandi con riquadro ad alto contrasto. Il messaggio EAV “Sistema in fase di test” viene escluso, mantenendo gli altri avvisi ufficiali.
Resoconto interno per stazione e giornata: soppressioni, orari effettivi manuali, passaggio segnato con ✓ e corse non più presenti dopo una lettura riuscita. La scomparsa non è una soppressione e non certifica un orario effettivo. Un errore di collegamento non provoca archiviazione automatica. Una soppressione revocata riporta la corsa ancora live nell’elenco se non presenta altre registrazioni che la archiviano. Il resoconto usa SQLite e richiede conservazione del volume dati per sopravvivere ai deploy.

Il monitor aggiorna anche il resoconto delle stazioni selezionate in EAV_MONITOR_STATIONS, usando le letture appena effettuate senza ulteriori chiamate a EAV. Il resoconto continua quindi ad aggiornarsi con il browser chiuso quando il monitor è attivo.

## Recupero, colori e notifiche strutturate
Nel resoconto della giornata corrente Annulla e recupera annulla passaggio, effettivi e soppressioni manuali. La revoca della regola di tratta vale su tutte le stazioni interessate. Una soppressione ufficiale EAV rimane valida. Una corsa scomparsa dal dato EAV può essere recuperata soltanto nell’elenco interno, con etichetta RECUPERATA, senza aggiungerla al tabellone pubblico.
La linea selezionata in pubblico e schermi filtra le stazioni del menu, mentre il tabellone mostra tutte le corse della stazione. Navette Torre Annunziata viola #7b2cbf, Poggiomarino verde. L1bis resta nell’admin.
Telegram legge i dati strutturati EAV tramite il monitor, anche a browser chiuso. Invio automatico per soppressioni, limitazioni, binari, ritardi e ripristini; rileva anche ritardi già presenti al primo ciclo e più variazioni nello stesso ciclo. Compila gli avvisi manuali con corsa, destinazione, ora e situazione oppure usa 📨 sulla riga del treno per precompilare. Simula mostra il testo completo; Invio prova è reale. Grassetto, numero in monospace e frase finale vengono applicati automaticamente.

Legenda pubblica: voce propria viola L1 Napoli – Torre Annunziata / Oplonti subito sotto L1 Napoli – Sorrento. L4 Napoli – Poggiomarino verde. Titolo Legenda linee adattato alla colonna senza fuoriuscite. Il segno ✓ archivia internamente e non rimuove la corsa dal pubblico; la rimozione dal pubblico resta un comando esplicito separato.

Correzione monitor: Monte Sant’Angelo ID 834 è inclusa nel CSV e nel catalogo server; anche una copia CSV precedente viene completata con la stazione L7. Non serve rimuovere 834 da EAV_MONITOR_STATIONS dopo questo aggiornamento.

## Ritardi al capolinea, soglia 10 minuti
Gli avvisi automatici di ritardo sono riferiti agli arrivi al capolinea effettivo della corsa. Le partenze vengono lette per confermare la destinazione, perché negli arrivi il campo EAV può contenere la provenienza. La corsa non viene notificata da stazioni intermedie, anche quando sono capolinea di un’altra linea. Se la destinazione non è ancora confermata dai dati, il sistema attende una lettura utile.
Un solo avviso al raggiungimento di almeno 10 minuti, più un solo avviso di rientro a 0 minuti dopo il precedente avviso. Le oscillazioni intermedie e i cicli successivi non generano duplicati. Una cella del ritardo vuota o non numerica non conferma il rientro. La deduplicazione per rete/corsa/giornata è conservata in SQLite: il volume dati deve essere persistente per mantenerla attraverso i deploy. La coda mantiene i retry solo per invii non confermati. I vecchi avvisi automatici di ritardo ancora in coda vengono annullati al primo avvio aggiornato; i messaggi manuali restano disponibili.
Capolinea monitorati per impostazione predefinita: Napoli Porta Nolana 1, Sorrento 62, Sarno 25, Baiano 84, Volla 70, Torre Annunziata/Oplonti 41, Poggiomarino 22, Montesanto 801, Licola 830, Torregaveta 819, Soccavo 821, Monte Sant’Angelo 834.
Su Render: EAV_MONITOR_STATIONS=1,62,25,84,70,41,22,801,830,819,821,834; EAV_MONITOR_TYPES=P,A; CSP_ENABLE_MONITOR=1. Per Volla il catalogo EAV usa Casoria Arpino - Volla (70).


Riconoscimento completo delle corse: catalogo ricostruito dalle righe Numero Treno dei quadri EAV, in entrambe le direzioni: 222 corse del precedente programma e 280 dal 17 ottobre 2026. Le navette Torre sono distinte dai treni Sorrento/Poggiomarino presenti nel quadro comune. Colori pubblici, filtri interni e monitor usano le stesse associazioni. Non cambia il colore uniforme richiesto per i numeri nell’admin. Fonti e metodo in fonti_corse_linee.json.

Regole di numerazione richieste dall’operatore, prioritarie su cataloghi e destinazione: 4→L4 Poggiomarino; 1 a quattro cifre→L1 Sorrento; 1 a cinque cifre→navette Torre Annunziata; 8→L8 Baiano; 6→L6 Sarno. Valgono anche per le corse verso Napoli e per il riconoscimento interno e monitor; i numeri nell’admin rimangono uniformi.
