<?php /* #?ini charset="utf-8"?

# Oracle (the ezoracle extension): "mode" (sevenx_paypal_transaction, sevenx_paypal_event, sevenx_paypal_vault) is an
# Oracle reserved word. The schema handler creates the column quoted ("MODE"); listed here, the driver quotes it in
# every statement, so the queries of sevenx_paypal stay as on every other database. Other databases ignore this file.
[QuotedIdentifierSettings]
TypeColumnNames[]=mode

*/ ?>
