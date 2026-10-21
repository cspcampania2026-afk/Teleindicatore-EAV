# Telegram e resoconto della giornata

## Pubblicazione

Estrai lo ZIP e sostituisci su GitHub tutto il contenuto della cartella `teleindicatore`, compresi i nuovi PHP, il Dockerfile e `pdf_helvetica_widths.json`. Attendi il deploy Live su Render e ricarica l’admin. Le nuove tabelle sono create automaticamente senza cancellare utenti o dati esistenti.

Non servono nuovi account esterni, librerie PDF a pagamento o nuove variabili. Mantieni `DATABASE_URL` per conservare utenti, storico e deduplicazione nel database esterno. Con SQLite locale senza persistenza, riavvii e deploy possono perdere questi dati.

## Regole Telegram

- Un solo avviso iniziale di ritardo per rete, corsa e giornata, quando il ritardo è di almeno 9 minuti.
- Il ritardo è letto dal tabellone **Arrivi del capolinea effettivo** della corsa. La destinazione è verificata sui tabelloni Partenze; il campo degli Arrivi può indicare l’origine e non viene usato per indovinare la direzione.
- Le stazioni intermedie e gli altri capolinea monitorati non generano altri avvisi di ritardo della stessa corsa.
- La nuova regola sostituisce quella degli ulteriori avvisi ogni aumento di 5 minuti: anche se il ritardo aumenta, non viene pubblicato un secondo avviso di ritardo.
- Resta un solo avviso di rientro a zero, dopo un precedente ritardo importante. Una cella vuota non conferma il rientro.
- Le intestazioni **LINEE VESUVIANE** e **LINEE FLEGREE** sono in grassetto e su una riga dedicata.
- Non partono notifiche automatiche per i cambi binario, nemmeno salvando un binario nel pannello. Per comunicarne uno serve comporre e inviare esplicitamente un messaggio.
- Al primo ciclo aggiornato, eventuali cambi binario automatici ancora in coda e ulteriori avvisi di ritardo duplicati non consegnati vengono annullati. I messaggi già pubblicati restano su Telegram; le bozze e gli invii manuali non vengono cancellati.

Un processo monitor deve essere attivo (`CSP_ENABLE_MONITOR=1`) per leggere i dati e inviare i messaggi. Render Free e le quote del database esterno non garantiscono continuità h24.

## Due schede nell’admin

**Monitor treni da effettuare** è la scheda iniziale: mantiene il quadro delle corse da gestire, con arrivi, partenze, binari, ritardi e strumenti operativi.

**Resoconto giornata** contiene corse archiviate e soppresse della stazione selezionata. Puoi scegliere la data, 25/50/100 corse per pagina e navigare con prima, precedente, numeri di pagina, successiva e ultima. Ricerca e filtro linea sono applicati prima della paginazione; il conteggio mostra anche il totale registrato.

Gli stati sono distinti e visibili:
- **PASSAGGIO AUTOMATICO**: la corsa non compare più dopo una lettura riuscita. Si conserva l’ultimo effettivo calcolato dal ritardo, distinto da un orario rilevato o manuale.
- **PASSAGGIO MANUALE**: passaggio segnato o orario effettivo registrato dall’operatore.
- **SOPPRESSO**: soppressione della sorgente o applicata internamente, evidenziata in rosso.
- **NON CONCLUSA**: ultimo dato presente prima del cambio giornata senza conferma di passaggio. Viene conservato senza dichiarare effettuata la corsa.

Nel resoconto della giornata corrente resta disponibile **Annulla e recupera**. Le giornate precedenti sono consultabili senza pulsanti di modifica.

## Dettagli della corsa

Clicca il numero della corsa, o una parte non interattiva della riga. Si apre una finestra con:
- arrivo e partenza teorici ed effettivi;
- binari, ritardi e stato;
- note interne e informazioni EAV di arrivo e partenza;
- soppressione per stazione, da stazione a stazione o su intero percorso, quando l’ambito è stato registrato;
- stazioni interessate e autore della regola manuale;
- attività e note sulla corsa con data, ora e operatore.

Le informazioni delle regole nuove vengono conservate anche dopo l’archiviazione. Per dati storici precedenti può essere disponibile soltanto la descrizione originariamente registrata: il sistema non inventa dettagli mancanti.

## PDF completo

Scegli stazione e giornata, apri Resoconto e premi **Scarica PDF completo**. Il PDF comprende tutte le corse registrate per quella stazione/giornata, non soltanto la pagina corrente e senza i filtri di ricerca o linea. Include anche eventuali corse ancora da effettuare o non concluse, con il relativo stato.

Il documento contiene orari, ritardi, binari, note, ambiti di soppressione e attività disponibili. È multipagina, con intestazioni e numeri di pagina. Non serve installare un programma PDF sul server e nessun dato viene inviato a un servizio esterno per generarlo.

## Copertura dalla mattina

La paginazione recupera **tutto ciò che è conservato** nel database. Non può ricostruire le corse che non sono mai state lette, né dati persi prima del collegamento al database persistente.

Il monitor raccoglie lo storico dei capolinea configurati e delle stazioni che vengono aperte nel pannello operativo. Le stazioni aperte vengono memorizzate come stazioni da seguire anche quando il browser viene chiuso, purché il processo monitor resti attivo. Per iniziare la raccolta di una stazione, selezionala una volta nell’admin.

Il resoconto e il PDF mostrano l’ora iniziale e l’ultimo aggiornamento della raccolta della giornata: questo intervallo non certifica l’assenza di interruzioni. Per una giornata completa il processo deve essere operativo dall’inizio del servizio. Durante un’interruzione di EAV, del server o del database alcune corse possono non essere osservate: il programma non le ricostruisce come se fossero dati reali.

## Aggiornamento admin e database remoto

Il monitor interno aggiorna con una pausa di 15 secondi dopo ogni risposta, con conto alla rovescia. Non sovrappone richieste e interrompe l’attesa del browser dopo 30 secondi mostrando un errore. Il resoconto viene caricato solo quando è aperta la sua scheda e non blocca la tabella live. Le risposte EAV già lette da meno di 15 secondi possono essere riutilizzate nell’admin.

Le scritture delle corse e del resoconto sono raggruppate in blocchi da 100, anziché una richiesta al database remoto per ciascuna corsa. Il monitor registra in blocco solo le destinazioni nuove o cambiate e legge le destinazioni note una sola volta per ciclo di stazione. Nessuna modifica ai circuiti pubblico e schermi.

## Effettivi e correzioni del resoconto

Arrivo e partenza effettivi si aggiornano con teorico più ritardo, con etichetta CALCOLATO. Zero minuti significa teorico uguale a effettivo. I manuali hanno precedenza. Il calcolo passa correttamente oltre mezzanotte; se il ritardo è sconosciuto o il treno soppresso non viene creato un nuovo effettivo automatico. Il valore calcolato rimane salvato quando la corsa passa nel resoconto.

Nella scheda Resoconto giornata, usare Note / modifica oppure aprire la corsa e scegliere Modifica resoconto. Sono modificabili destinazione, teorici, effettivi, binari, ritardi, note e informazioni integrative, anche nelle giornate precedenti. Le modifiche sono conservate nel database separatamente dalle letture automatiche e riportate nei dettagli e nel PDF. Sono registrati autore, data e valori precedenti. Le correzioni del resoconto non cambiano il circuito pubblico e non inviano messaggi Telegram. Le tabelle aggiuntive sono create automaticamente al primo accesso, senza cancellare utenti o dati esistenti.


## Grafici dei ritardi e capolinea
Nel Resoconto aprire “Grafici · indice dei ritardi della giornata”. Filtri: stazione, data e linea. Si considerano tutte le pagine e le corse osservate ancora nel monitor. Ogni corsa vale una volta, con il suo ultimo ritardo registrato: arrivo se disponibile, altrimenti sola partenza. Ritardi sconosciuti e soppressioni esclusi. Indice = corse con ritardo ≥9 minuti / corse con ritardo noto ×100. I grafici non ricostruiscono dati mai raccolti e non rappresentano tutte le corse della rete.

PARI/DISPARI dipendono dall'ultima cifra del numero. Vesuviane: pari verso Napoli Porta Nolana, dispari verso i capolinea di linea. Cumana e Circumflegrea: dispari verso Montesanto, pari verso la linea. L7: pari Monte Sant’Angelo → Soccavo; dispari Soccavo → Monte Sant’Angelo. Direzione visibile nei dettagli e passando sul contrassegno di parità.

L7: i numeri 5xxxx presenti a Soccavo e le corse di Monte Sant’Angelo sono riconosciuti come L7, senza dipendere dal solo prefisso 7.

Un movimento assente ai capolinea è indicato “Non prevista” quando la direzione ferroviaria ne conferma l'assenza. Negli altri casi resta “Non rilevato”. Non vengono creati movimenti o orari mancanti. Il dato presente nella sorgente resta conservato.

Nel PDF “ESITO NON RILEVATO” sostituisce l'ambigua voce “NON CONCLUSA”: l'ultima osservazione è rimasta senza conferma di passaggio prima della fine della raccolta (es. interruzione del monitor, sonno del server, errore sorgente o fine giornata). Non indica una corsa non effettuata. Gli effettivi calcolati sono stime e non costituiscono conferma del passaggio. Le note e le correzioni del resoconto restano disponibili per integrare l'esito.


Il PDF presenta le corse in una tabella con intestazioni ripetute su ogni pagina: corsa, destinazione/direzione, stato, arrivo e partenza (teorico, effettivo, binario, ritardo). Note, soppressioni, correzioni e attività sono mantenute sotto la relativa riga, con continuazione sulle pagine successive.


Se il ritardo non è segnalato (vuoto, assente o zero), l’effettivo calcolato coincide con il teorico, anche nei resoconti già salvati e nel PDF. È una convenzione di visualizzazione interna, non una conferma di passaggio. Il dato di ritardo sconosciuto rimane tale per statistiche e Telegram. Manuali e correzioni hanno precedenza; nessun effettivo viene generato per movimenti non previsti o soppressi.


Tipografia resoconto: Segoe UI con alternative locali, numeri allineati e testi più grandi e marcati. PDF con dati tabellari in grassetto a 9 pt e note a 9,5 pt, righe adattate al contenuto. Non richiede font o servizi esterni.


## Analisi interattiva delle linee
Aprire Resoconto → Analisi interattiva dei ritardi. Sei grafici: media per linea, massimo per linea, quota di corse ≥9 minuti per linea, distribuzione per fasce, media per ora e campioni per ora. Clic su linea/ora per filtrare, clic su fascia per selezionare le corse in tabella; clic sul numero per i dettagli. Sono disponibili ambito tutte le stazioni raccolte o singola stazione, filtro linea e intervallo orario, solo arrivi al capolinea, reset e pausa aggiornamento live. La data è quella del resoconto. I filtri dell’analisi sono indipendenti dalla linea e dalla ricerca del monitor.

Gli aggiornamenti conservano i grafici, il pannello aperto e i filtri: cambiano soltanto numeri e forme. Le richieste non si sovrappongono. Con errori di lettura i dati precedenti restano visibili con avviso; un cambio di data o ambito attende la nuova risposta senza azzerare la vista.

Il confronto usa soltanto dati già raccolti, non interroga EAV e non invia messaggi. Una osservazione per numero corsa/linea/giornata, preferendo l’arrivo al capolinea; in sua assenza, ultima osservazione disponibile. Il riferimento e la stazione sono espliciti nella tabella. Numeri riutilizzati per più servizi nello stesso giorno sono aggregati: il dato non equivale al conteggio di tutte le corse programmate. Le linee senza campioni sono indicate come prive di dati; ritardi sconosciuti e soppressioni sono esclusi dai valori di ritardo. I grafici a confronto tra linee mantengono tutte le linee nel periodo scelto, mentre gli indici, la distribuzione, l’andamento orario e la tabella seguono anche la linea selezionata.
