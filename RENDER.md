# Pubblicazione su Render

Apri la cartella `teleindicatore` dello ZIP e carica **il suo contenuto** nella radice del repository GitHub collegato a Render. Non caricare soltanto lo ZIP. Devono esserci insieme `Dockerfile`, `deploy/`, `index.html`, i PHP e gli altri file del progetto.

## Campi della schermata

| Campo | Valore |
|---|---|
| Name | `teleindicatore-csp`, o un altro nome disponibile |
| Language | `Docker` |
| Branch | `main` |
| Root Directory | Vuoto, quando hai caricato i file nella radice del repository |
| Dockerfile Path | `./Dockerfile` |
| Docker Command | Vuoto: usa il comando del Dockerfile |
| Health Check Path, se richiesto | `/index.html` |

Se mantieni invece l'intera cartella `teleindicatore` nel repository, lascia Root Directory vuoto e configura **Dockerfile Path = `teleindicatore/Dockerfile`** e **Docker Build Context = `teleindicatore`**, nelle impostazioni Docker avanzate. La soluzione con i file nella radice è più semplice e non richiede questi due percorsi diversi.

La regione può restare quella selezionata. Prima di cliccare Deploy, controlla anche che il campo Name in cima non sia vuoto: “There are errors above” non identifica da solo quale campo manca.

## Variabili d’ambiente

Il Dockerfile imposta già `CSP_DATA_DIR=/var/lib/csp-data` e usa la porta `PORT` assegnata da Render. Il monitor è disattivato inizialmente, così il primo deploy può essere usato per controllare il sito.

Per l’area admin aggiungi:

| Chiave | Valore |
|---|---|
| `CSP_ADMIN_USER` | Il tuo nome utente admin |
| `CSP_ADMIN_PASSWORD` | La password che scegli, salvata come segreto solo in Render |

Per Telegram aggiungi direttamente nella sezione Environment di Render:

| Chiave | Valore |
|---|---|
| `EAV_TELEGRAM_BOT_TOKEN` | Token del bot |
| `EAV_TELEGRAM_CHANNEL_ID` | ID o username del canale |
| `EAV_TELEGRAM_GROUP_ID` | ID del gruppo |
| `EAV_MONITOR_STATIONS` | Ad esempio `27,801,821,834`; scegli le stazioni da monitorare |
| `EAV_MONITOR_TYPES` | `P,A` oppure solo `P` |
| `EAV_POLL_SECONDS` | `60` |
| `CSP_ENABLE_MONITOR` | `1`, solo quando vuoi avviare monitor e invii reali |

Non caricare token o password su GitHub. Non è prevista una password predefinita. In alternativa puoi continuare a usare `CSP_ADMIN_PASSWORD_HASH`: se presente e non vuoto ha precedenza su `CSP_ADMIN_PASSWORD`. Per usare la password semplice rimuovi il vecchio hash dalla configurazione. Al primo login valido, se non esistono utenti, viene creato il primo amministratore con password salvata come hash. Da quel momento gli account sono gestiti dal pannello: le variabili CSP_ADMIN_* non modificano le password esistenti. Leggi GESTIONE_UTENTI.md. Il bot deve poter pubblicare nelle destinazioni configurate.

## Prova gratuita e funzionamento h24

Per conservare i dati su Render Free senza comprare un disco, usa PostgreSQL esterno con `DATABASE_URL`, come spiegato in **DATABASE_GRATUITO.md**. Il Dockerfile include già il driver e le tabelle vengono inizializzate automaticamente al primo utilizzo. In questo caso `CSP_DATA_DIR` contiene soltanto file temporanei.

Senza `DATABASE_URL`, il programma usa SQLite: su Render Free questi dati sono temporanei e possono perdersi al riavvio o al deploy. Un eventuale disco persistente resta un'alternativa per SQLite, ma non è richiesto per PostgreSQL.

Il database esterno non mantiene acceso il servizio Render Free. Un monitor h24 richiede un processo sempre in esecuzione e risorse sufficienti del database; questa configurazione gratuita non garantisce disponibilità continua. Non attivare piani a pagamento se vuoi restare a costo zero.

Non avviare un altro monitor sullo stesso progetto. Supervisor riavvia il processo se si arresta e Render riavvia il servizio secondo le proprie regole. La sorgente EAV e Telegram restano dipendenze esterne; h24 non garantisce che queste rispondano sempre.

## Verifiche

Il Dockerfile verifica le estensioni PHP, esegue il controllo sintattico dei PHP ed esegue i test offline SQLite durante la build. I test disabilitano Telegram e la rete e creano un database temporaneo. Se falliscono, Render interrompe la build e mostra l’errore nei log.

Questa immagine non è stata costruita in questo ambiente, che non dispone di Docker. Sono stati eseguiti i controlli sintattici PHP, i test offline SQLite e i test del parser EAV con PHP 8.3, oltre ai controlli shell e ai percorsi della configurazione. Attendi una build completata su Render prima di considerare l’installazione verificata.

Quando il deploy è completato:

- `/index.html`: pubblico;
- `/schermi.php`: schermi;
- `/admin.php`: gestione interna;
- `/anteprima_admin.html`: demo con dati simulati.

Nel pannello admin controlla lo stato del monitor e la conferma degli invii Telegram.

Documentazione: https://render.com/docs/docker · https://render.com/docs/free · https://render.com/docs/disks
