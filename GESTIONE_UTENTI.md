# Account personali per il pannello interno

## Installazione

1. Estrai il pacchetto e carica **tutto il contenuto** della cartella `teleindicatore` nella stessa posizione dei file attuali nel repository GitHub collegato a Render. Non caricare soltanto lo ZIP o il solo `index.html`: servono anche i nuovi PHP.
2. Conserva le variabili già configurate su Render, comprese quelle del bot. Per il primo amministratore usa gli attuali `CSP_ADMIN_USER` e `CSP_ADMIN_PASSWORD` oppure `CSP_ADMIN_PASSWORD_HASH` (l'hash, se presente, ha precedenza).
3. Per restare su Render Free, collega PostgreSQL esterno impostando `DATABASE_URL`: segui **DATABASE_GRATUITO.md**. In alternativa SQLite locale richiede un disco persistente. Senza entrambe le soluzioni, account e registro possono andare persi al riavvio o al deploy. Un database esterno nuovo non importa automaticamente i dati SQLite già esistenti.
4. Esegui il deploy e attendi lo stato Live. I test degli account sono eseguiti anche durante la build.
5. Apri `https://teleindicatore-eav.onrender.com/admin.php` e accedi con le credenziali attuali. Le vecchie sessioni senza identità personale vengono invalidate: è normale dover rifare il login.

Il primo login valido, **soltanto se l'elenco utenti è vuoto**, crea il primo amministratore. Il database operativo viene aggiornato senza cancellare corse, avvisi o code Telegram. Le password degli account sono conservate come hash.

## Creare un operatore

1. Nell'area interna apri **Il mio account / Gestione utenti**, nella pagina `utenti.php`.
2. In **Crea un account**, inserisci un nome univoco, per esempio `mario.rossi`, scegli **Operatore** e imposta una password temporanea di almeno 12 caratteri (massimo 72 byte).
3. Premi **Crea account**. Comunica privatamente le credenziali al destinatario: il sistema non le invia su Telegram o email.
4. La persona apre lo stesso link `admin.php`, accede e sostituisce la password temporanea con una personale prima di usare il pannello.

I nomi utente ammettono 3–64 caratteri: lettere, numeri, punto, trattino e underscore; devono iniziare con una lettera o un numero. Maiuscole e minuscole non creano account diversi. Il nome resta stabile per attribuire correttamente il registro.

## Permessi

| Funzione | Operatore | Amministratore |
|---|---|---|
| Consultare e gestire corse e resoconto | Sì | Sì |
| Passaggi, orari effettivi, soppressioni e recuperi | Sì | Sì |
| Pubblicare e ritirare avvisi | Sì | Sì |
| Simulare o accodare messaggi Telegram manuali | Sì | Sì |
| Consultare stato monitor e registro operativo | Sì | Sì |
| Verificare bot, leggere ID chat, ritentare o annullare la coda | No | Sì |
| Creare account, cambiare ruoli, disabilitare e riattivare utenti | No | Sì |
| Reimpostare la password di altri utenti | No | Sì |
| Cambiare la propria password conoscendo quella attuale | Sì | Sì |

I permessi sono controllati sul server anche per chiamate dirette alle API. I segreti Telegram continuano a essere configurati su Render: non sono mostrati né modificabili nel pannello.

## Disabilitazione e password dimenticata

Da **Utenti**, scegli **Accesso → Disabilitato** e **Salva utente**. Il login viene bloccato e le sessioni aperte perdono validità alla richiesta successiva. Per ripristinare l'accesso scegli **Attivo**: account e registro restano conservati.

Per una password dimenticata, apri **Reimposta password**, inserisci una password temporanea e conferma. Le sessioni vengono revocate; al prossimo accesso sarà obbligatorio scegliere una password personale. Per il proprio account si usa il modulo **Cambia la tua password**, indicando quella attuale.

Non puoi disabilitare o declassare il tuo account e deve restare almeno un amministratore attivo. Crea un secondo amministratore fidato per recuperare l'accesso se il primo dimentica la password.

**Dopo la creazione del primo account, cambiare le variabili `CSP_ADMIN_*` su Render non cambia le password degli utenti e non crea un accesso alternativo.** Usa Gestione utenti: le variabili servono solo a inizializzare un database senza account.

## Registro e sessioni

In Gestione utenti trovi le ultime 100 attività con data, ora, utente, azione e dettagli; nel quadro operativo resta il registro recente. Sono attribuiti anche accessi, modifiche agli account e invii manuali accodati. Gli eventi automatici mantengono l'identità del sistema. Le password non vengono incluse nel registro.

Le sessioni scadono dopo un'ora di inattività. Un pannello che continua ad aggiornarsi mantiene attiva la sessione: al termine del turno premi **Esci**. Cambiare la propria password revoca le altre sessioni; cambiare un ruolo, disabilitare un account o reimpostare una password revoca tutte le sessioni dell'account interessato.

I circuiti pubblico e schermi di stazione restano accessibili senza login.
