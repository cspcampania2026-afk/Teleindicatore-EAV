> Aggiornamento: le regole e le funzioni correnti sono descritte in **MONITOR_RESOCONTO.md** (singolo avviso di ritardo, niente cambi binario automatici, schede, PDF e paginazione).

# Collegare il bot Telegram e fare una prova

## 1. Bot e autorizzazioni

- Se il bot esiste già, usa il suo token. Altrimenti apri **@BotFather** su Telegram, invia `/newbot` e segui i passaggi.
- Aggiungi il bot al canale come amministratore con diritto di **pubblicare messaggi**.
- Se vuoi inviare anche al gruppo, aggiungilo al gruppo e consentigli di scrivere.

## 2. Configurazione su Render

Apri **Environment → Add Environment Variable** nel servizio del teleindicatore.

| Key | Value |
|---|---|
| `EAV_TELEGRAM_BOT_TOKEN` | Token fornito da BotFather, inserito solo fra i segreti di Render |
| `EAV_TELEGRAM_CHANNEL_ID` | `@nome_del_canale` se pubblico; altrimenti ID numerico del canale |
| `EAV_TELEGRAM_GROUP_ID` | ID numerico del gruppo, se vuoi usarlo |
| `EAV_MONITOR_STATIONS` | Ad esempio `27,801` per San Giorgio a Cremano e Montesanto |
| `EAV_MONITOR_TYPES` | `P,A` per partenze e arrivi |
| `EAV_POLL_SECONDS` | `60` per il ciclo del monitor server |
| `CSP_ENABLE_MONITOR` | `1` per avviare monitor e invii |

Premi **Save, rebuild, and deploy** e attendi il deploy completato. Il token non va inserito nei file su GitHub.

## 3. Verifica del collegamento e ID delle chat

Nell’area interna, vai a **Messaggio breve tramite bot Telegram → Collega e verifica il bot**.

1. Premi **Verifica collegamento bot**. Se il token è valido compare il nome del bot.
2. Per trovare un ID, invia `/start` nel gruppo dopo aver aggiunto il bot, oppure pubblica un post nel canale dove il bot è amministratore.
3. Premi **Leggi ID chat**. Sono mostrati soltanto nome, tipo e ID delle chat disponibili negli aggiornamenti del bot, non il contenuto dei messaggi.
4. Copia l’ID nella variabile corrispondente di Render e applica la modifica con un deploy.

Questi pulsanti non inviano messaggi. Se il bot ha già un webhook, il pannello lo segnala e non lo modifica. In quel caso puoi usare lo username del canale pubblico oppure un ID già noto. Un bot in modalità privacy vede i comandi a lui rivolti; usa eventualmente `/start@nome_bot` nel gruppo.

## 4. Simulazione: nessun invio

Scrivi un messaggio breve, per esempio:

> Prova di collegamento: nessuna variazione al servizio.

Premi **Simula senza inviare**. Compare l’anteprima con l’etichetta **SIMULAZIONE**. Il messaggio non viene inviato, non entra nella coda e non viene pubblicato negli Avvisi. La simulazione funziona anche prima di configurare il token.

## 5. Prova reale e messaggi a mano

Seleziona **Canale associato** oppure **Gruppo associato** e premi **Invia prova [PROVA]**. Dopo la conferma, il messaggio entra in coda con il prefisso `[PROVA]`. Il monitor deve essere attivo per consegnarlo.

Controlla **Stato monitor h24, coda invii e registro**: “In coda” non è una consegna; “Consegnato a Telegram” significa che l’API ha confermato l’invio. Se fallisce, il dettaglio dell’errore rimane nel pannello e il monitor riprova.

Per un avviso normale, scrivi il testo e usa **Invia messaggio**. Sono disponibili modelli per soppressione, limitazione, binario e ritardo. Sostituisci tutti i campi tra parentesi prima di inviare.

## 6. Messaggi brevi

Massimo **280 caratteri** per i messaggi manuali e gli avvisi pubblicati; le notifiche automatiche sono contenute in circa 220 caratteri. Esempi simulati:

- `⛔ Corsa 9001 per Torregaveta soppressa.`
- `⚠️ Corsa 9001 limitata a Bagnoli.`
- `🚉 Corsa 9001 · Montesanto: partenza dal binario 3.`
- `⏱ Corsa 9001 per Torregaveta: ritardo 10 min.`

Il monitor segnala soppressioni, limitazioni riconoscibili nella sorgente, cambi di binario già assegnato, variazioni di ritardo e ripristini. Non invia la lista di tutte le nuove corse né le lunghe sequenze di fermate. Una corsa che scompare dal teleindicatore non viene automaticamente dichiarata soppressa.

## 7. Avvisi sui siti e Telegram

La sezione **Avvisi per pubblico e schermi** è distinta dall’invio Telegram. Puoi pubblicare un avviso per tutte le stazioni o per una sola stazione, scegliere la durata e ritirarlo. Se selezioni **Invia anche a Telegram**, la pubblicazione e l’accodamento vengono salvati insieme. Senza selezionare questa opzione non parte alcun messaggio Telegram.

La revoca di un avviso lo rimuove dal pubblico e dagli schermi, ma non cancella un messaggio già consegnato su Telegram.

## 8. Funzionamento continuativo

Il monitor vive sul server e non dipende da una pagina aperta. Il piano Free di Render può sospendere il servizio e non conserva il database su disco persistente. Per questa versione, funzionamento continuativo e conservazione di avvisi/coda richiedono un servizio sempre attivo e un disco montato su `/var/lib/csp-data`. Un controllo esterno come UptimeRobot non fornisce persistenza del database.

Documentazione: https://core.telegram.org/bots/tutorial · https://core.telegram.org/bots/api

## Errore 401 Unauthorized e destinazione non configurata
Se compare 401, Telegram non riconosce il token del bot. In @BotFather scegli il bot tramite /mybots e copia il suo API Token. Su Render sostituisci EAV_TELEGRAM_BOT_TOKEN con il token completo, senza virgolette, senza prefisso bot, senza spazi. Salva ed esegui il deploy. Premi Verifica collegamento bot: deve comparire lo username del tuo bot. Non pubblicare il token in GitHub o nei messaggi.

Per il canale @eavavvisi imposta EAV_TELEGRAM_CHANNEL_ID=@eavavvisi. Se EAV_TELEGRAM_GROUP_ID non è impostata, usa Canale associato: il gruppo non è disponibile. Il bot deve essere amministratore del canale con permesso di pubblicare. Dopo la verifica puoi annullare i vecchi messaggi in coda oppure ritentarli con Verifica bot e ritenta coda. Quest’ultimo pulsante abilita invii reali al prossimo ciclo del monitor.

## Nuovo modello automatico e manuale
Il monitor usa lo stesso modello del compositore interno: intestazione EAV - Avvisi (Linee Vesuviane o Linee Flegree), corsa in monospace, destinazione in grassetto, orario programmato e campo specifico per ritardo, soppressione, limitazione o nuovo binario. Per disservizi aggiunge la frase di scuse. Il pulsante 📨 su una corsa compila i campi; puoi modificarli e aggiungere una nota. Simula senza inviare mostra l’anteprima.
Il monitor richiede CSP_ENABLE_MONITOR=1, token valido e canale configurato. EAV_MONITOR_TYPES=P,A, EAV_POLL_SECONDS=60; EAV_MONITOR_STATIONS omessa monitora l’elenco configurato, oppure puoi indicare gli ID desiderati separati da virgola. Il controllo è indipendente dalla linea selezionata nel sito e dal browser. I vecchi messaggi già in coda mantengono il formato originario; puoi annullarli prima di provare il nuovo modello.

## Ritardi al capolinea, soglia 9 minuti
Gli avvisi automatici di ritardo sono riferiti agli arrivi al capolinea effettivo della corsa. Le partenze vengono lette per confermare la destinazione, perché negli arrivi il campo EAV può contenere la provenienza. La corsa non viene notificata da stazioni intermedie, anche quando sono capolinea di un’altra linea. Se la destinazione non è ancora confermata dai dati, il sistema attende una lettura utile.
Un solo avviso al raggiungimento di almeno 9 minuti, più un solo avviso di rientro a 0 minuti dopo il precedente avviso. Le oscillazioni intermedie e i cicli successivi non generano duplicati. Una cella del ritardo vuota o non numerica non conferma il rientro. La deduplicazione per rete/corsa/giornata è conservata nel database. Per mantenerla attraverso i deploy utilizzare il PostgreSQL esterno già configurato oppure SQLite con dati persistenti. La coda mantiene i retry solo per invii non confermati. I vecchi avvisi automatici di ritardo ancora in coda vengono annullati al primo avvio aggiornato; i messaggi manuali restano disponibili.
Capolinea monitorati per impostazione predefinita: Napoli Porta Nolana 1, Sorrento 62, Sarno 25, Baiano 84, Volla 70, Torre Annunziata/Oplonti 41, Poggiomarino 22, Montesanto 801, Licola 830, Torregaveta 819, Soccavo 821, Monte Sant’Angelo 834.
Su Render: EAV_MONITOR_STATIONS=1,62,25,84,70,41,22,801,830,819,821,834; EAV_MONITOR_TYPES=P,A; CSP_ENABLE_MONITOR=1. Per Volla il catalogo EAV usa Casoria Arpino - Volla (70).

