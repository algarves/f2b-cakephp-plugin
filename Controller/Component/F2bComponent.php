<?php

App::uses('Component', 'Controller');
App::uses('HttpSocket', 'Network/Http');
App::uses('CakeTime', 'Utility');

/**
 *
 * F2b Component, desenvolvido para auxiliar nas tarefas envolvendo
 * o webservice de transações da F2b para cobranças, bem como redirecionamentos e tratamento dos retornos.
 *
 *
 * PHP versions 5+
 * Copyright 2010-2017, Khristian Algarves (@khrisnet)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @author      Khristian Algarves
 * @link        https://github.com/khrisnet/f2b-cakephp-plugin/
 * @license     MIT License (http://www.opensource.org/licenses/mit-license.php)
 * @version     2.1
 */

class F2bComponent extends Component {

    private $ws = array(
        "protocol"  => "https",
        "host"      => "www.f2b.com.br",
        "port"      => 443,
        "service"   => "WSBilling",
        "timeout"   => 30,
        "encoding"  => "ISO-8859-1",
        "version"   => "1.0"
    );

    /**
     *
     * Instancia do Controller
     * @var Controller
     */
    public $Controller = null;

    public $components = array('Session');

    /**
     * Se vai usar o ambiente (email: teste@f2b.com.br) de teste da F2b.
     * @var bool
     */
    public $devMode = true;

    /**
     * Código da Conta junto a F2b
     * @var string
     */
    public $accountId = null;

    /**
     * Nome da empresa/cliente junto a F2b para consumo WS
     * @var string
     */
    public $companyName = null;

    /**
     * Senha da conta definida junto a F2b para consumo WS
     * @var string
     */
    public $password = null;

    public $cobrancaValor = null;
    public $cobrancaNumeroDocumento = null;
    public $cobrancaTaxa = null;
    public $cobrancaDemonstrativo =  array();
    public $cobrancaMultaValor = null;
    public $cobrancaMultaMoraDia = null;
    public $cobrancaVencimento = null;

    public $cobrancaCreditoInicial = null;
    public $cobrancaCreditoFinal = null;

    public $cobrancaSacadoCodigo;
    public $cobrancaSacadoNome;
    public $cobrancaSacadoEmail = array();
    public $cobrancaSacadoEnderecoLogradouro = null;
    public $cobrancaSacadoEnderecoNumero = null;
    public $cobrancaSacadoEnderecoComplemento = null;
    public $cobrancaSacadoEnderecoBairro = null;
    public $cobrancaSacadoEnderecoCidade = null;
    public $cobrancaSacadoEnderecoEstado = null;
    public $cobrancaSacadoEnderecoCep = null;

    public $cobrancaSacadoTelefoneDDD = null;
    public $cobrancaSacadoTelefoneNumero = null;

    public $cobrancaSacadoCelDDD = null;
    public $cobrancaSacadoCelNumero = null;

    public $cobrancaSacadoCpfCnpj = null;
    public $cobrancaSacadoTipo = null;

    public $cobrancaSacadoObs = null;

    public $acaoCobrancaNumero = null;
    public $acaoCobrancaCancelarCobranca = null;
    public $acaoCobrancaRegistrarPagamento = null;
    public $acaoCobrancaRegistrarPagamentoValor = null;
    public $acaoCobrancaDtRegistrarPagamento = null;
    public $acaoCobrancaCancelarMulta = null;
    public $acaoCobrancaPermitirPagamento = null;
    public $acaoCobrancaDtPermitirPagamento = null;
    public $acaoCobrancaReenviarEmail = null;
    public $acaoCobrancaEmailToSend = null;
    public $acaoCobrancaAgendamentoNumero = null;
    public $acaoCobrancaAgendamentoCancelarAgendamento = null;

    /**
     * ID do XML enviado na requisição a F2b.
     * @var string
     */
    protected $xmlUID = null;

    /**
     * Array público que guarda o último erro que aconteceu na requisição.
     * @var array
     */
    public $erro = null;

    public function __construct(ComponentCollection $collection, $settings = array()) {
        parent::__construct($collection, $settings);

        $this->devMode = Configure::read('F2b.dev_mode');
        $this->companyName = Configure::read('F2b.company_name');

        $this->accountId = Configure::read('F2b.account_id');
        $this->password = Configure::read('F2b.password');

        $this->xmlUID = md5(uniqid(rand(), true));
    }

    public function startup(Controller $controller) {
        parent::startup($controller);
        $this->Controller = $controller;
    }

    /**
     * Método responsável por fazer uma pré análise dos atributos da transação,
     * baseado em algumas regras de negócio da F2b.
     */
    public function analisarParametros() {
        if(is_null($this->accountId))
            throw new Exception("Não foi definido o ID da conta junto a F2b.");

        if(is_null($this->password))
            throw new Exception("Não foi definido a senha da conta junto a F2b.");

        if(is_null($this->companyName))
            throw new Exception("Não foi definido o nome da empresa/conta junto a F2b.");
    }

    /**
     * Método responsável por converter a data do formato Cake para o da F2b
     * para ser usada na construção dos XML's
     *
     * @param  string $data Exemplo formato: 1990-05-05 15:00:00
     * @return string       Data no formato: 1990-05-05T150:00:00 or 1990-05-05
     */
    public function converterData($data = null, $dateAndTime = true) {
        if(is_null($data)) return false;
        if ($dateAndTime) {
            return CakeTime::format('Y-m-d', $data) . 'T' . CakeTime::format('H:i:s', $data);
        }
        return CakeTime::format('Y-m-d', $data);
    }

    /**
     * Método responsável por converter valores em money para FLOAT no formato
     * suportado pelo WebService da F2b.
     *
     * @param  money $valor  ex: 1.200,20
     * @return float          ex: 1200.20
     */
    public function converterValor($valor = null, $casasDecimais = 2) {
        if(is_null($valor)) return false;
        return number_format($valor, $casasDecimais, '.','');
    }

    /**
     * Convert BR tags to newlines and carriage returns.
     *
     * @param string The string to convert
     * @param string The string to use as line separator
     * @return string The converted string
     */
    public function br2nl($string, $separator = PHP_EOL) {
        $separator = in_array($separator, array("\n", "\r", "\r\n", "\n\r", chr(30), chr(155), PHP_EOL)) ? $separator : PHP_EOL;  // Checks if provided $separator is valid.
        return preg_replace('/\<br(\s*)?\/?\>/i', $separator, $string);
    }

    public function tratarErrosRetorno($resultado = null) {
        if(is_null($resultado)) return false;
        if(isset($resultado['Envelope']['SOAP-ENV:Body']['m:F2bCobrancaRetorno']['log'])) {
            if ($resultado['Envelope']['SOAP-ENV:Body']['m:F2bCobrancaRetorno']['log'] != 'OK') {
                throw new Exception($this->br2nl(sprintf('F2b: %s', $resultado['Envelope']['SOAP-ENV:Body']['m:F2bCobrancaRetorno']['log'])));
            }
        }
        if(isset($resultado['Envelope']['SOAP-ENV:Body']['m:F2bAcaoCobrancaRetorno']['log'])) {
            if ($resultado['Envelope']['SOAP-ENV:Body']['m:F2bAcaoCobrancaRetorno']['log'] != 'OK') {
                throw new Exception($this->br2nl(sprintf('F2b: %s', $resultado['Envelope']['SOAP-ENV:Body']['m:F2bAcaoCobrancaRetorno']['log'])));
            }
        }
        if(isset($resultado['Envelope']['SOAP-ENV:Body']['m:F2bSegundaViaRetorno']['log'])) {
            if ($resultado['Envelope']['SOAP-ENV:Body']['m:F2bSegundaViaRetorno']['log'] != 'OK') {
                throw new Exception($this->br2nl(sprintf('F2b: %s', $resultado['Envelope']['SOAP-ENV:Body']['m:F2bSegundaViaRetorno']['log'])));
            }
        }
        if(isset($resultado['Envelope']['SOAP-ENV:Body']['m:F2bSituacaoCobrancaRetorno']['log'])) {
            if ($resultado['Envelope']['SOAP-ENV:Body']['m:F2bSituacaoCobrancaRetorno']['log'] != 'OK') {
                throw new Exception($this->br2nl(sprintf('F2b: %s', $resultado['Envelope']['SOAP-ENV:Body']['m:F2bSituacaoCobrancaRetorno']['log'])));
            }
        }
        return true;
    }

    /**
     * Método responsável por enviar os XML's para o WebService da F2b.
     *
     * @param  string $xml XML contendo as informações das requisições.
     * @return mixed       Se tudo certo o retorno será o XML de retorno
     * da f2b já transformado em array, em caso de erro é lançado uma
     * exceção com o erro e código retornado pelo WebService da F2b.
     */
    public function enviar($xml) {

        $urlWS = sprintf("%s://%s:%s/%s", $this->ws['protocol'], $this->ws['host'], $this->ws['port'], $this->ws['service']);

        $this->log(sprintf('Estabelecendo conexão com a chamada do serviço: %s', $urlWS), 'f2b');
        $this->log(sprintf('SOAPRequest (XML): %s', $xml), 'f2b');

        $headers = array(
            "Content-type: text/xml; charset=\"{$this->ws['encoding']}\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "SOAPAction: " . $urlWS,
            "Content-length: " .strlen($xml),
        );

        // PHP cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ($this->ws['port'] == 443 ? true : false));
        curl_setopt($ch, CURLOPT_URL, $urlWS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->ws['timeout']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml); // the SOAP request
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resultado = curl_exec($ch);
        curl_close($ch);

        $this->log(sprintf('SOAPResponse (XML): %s', print_r($resultado, true)), 'f2b');

        $resultado = Xml::toArray(Xml::build($resultado));

        // Verifica se retornou algum erro
        $this->tratarErrosRetorno($resultado);

        return $resultado;
    }

    /**
     * Método responsável por finalizar uma transação depois que os dados tiverem sido corretamente definidos.
     * @param string $tipo
     * @return bool|mixed
     */
    public function transmitir($tipo = 'F2bCobranca') {
        try {
            $this->analisarParametros();
        } catch (Exception $e) {
            $this->erro = array();
            $this->erro['mensagem'] = $e->getMessage();
            return false;
        }
        try {
            $retorno = $this->enviar($this->buildXml($tipo));
        } catch (Exception $e) {
            $this->erro = array();
            $this->erro['mensagem'] = $e->getMessage();
            return false;
        }
        return $retorno;
    }

    /**
     * Método responsável pelo envelopamento e padronização do XML a ser enviado na requisição
     * @param null $tipo
     * @return mixed
     */
    public function buildXml($tipo = null) {
        $elementoBase = sprintf('m:%s', ucfirst($tipo));

        // Chama funcao de acordo com o $tipo
        $xml = call_user_func([$this, $tipo], $elementoBase);

        // Adiciona envelopamento SOAP
        $xmlSoap['soap-env:Envelope'] = array(
            'xmlns:soap-env'    => 'http://schemas.xmlsoap.org/soap/envelope/',
            'soap-env:Body'     => $xml
        );

        // Converte Array em XML
        $xml = Xml::fromArray($xmlSoap, array(
            'encoding'  => $this->ws['encoding'],
            'version'   => $this->ws['version']
        ));

        return $xml->asXML();
    }

    private function f2bCobranca($elementoBase) {
        $this->ws['service'] = 'WSBilling';

        $xml[$elementoBase] = array(
            'xmlns:m' => 'http://www.f2b.com.br/soap/wsbilling.xsd'
        );

        // Mensagem
        $xml[$elementoBase]['mensagem'] = array(
            '@data'     => date('Y-m-d'),
            '@numero'   => $this->xmlUID,
            '@tipo_ws'  => 'WebService' # opcional
        );

        // Sacador
        $xml[$elementoBase]['sacador'] = array(
            '@conta'    => $this->accountId,
            '@'         => $this->companyName
        );

        // Cobranca
        $xml[$elementoBase]['cobranca'] = array(
            '@valor'                => $this->converterValor($this->cobrancaValor),
            '@tipo_cobranca'        => 'B',                                         # opcional ( B: Boleto | C: Cartão de crédito| D: Cartão de débito | T: Transferência Online ) - Ex: "BCD" (aceitará Boleto, Cartão de crédito e Cartão de débito)
            '@num_dcument'          => $this->cobrancaNumeroDocumento,              # opcional ( número próprio do documento )
            '@cod_banco'            => '237',                                       # opcional ( 001* | 033 | 104 | 237* | 341* | 409 | VISA | AMEX | MAST | ELO | OIPAG | DINER | DISCO | F2B: Transf. F2b ) - marcados com (*) aceita tanto boleto como transferencia
            '@taxa'                 => $this->converterValor($this->cobrancaTaxa),  # opcional # ( valor em reais ou porcentagem da taxa de cobrança )
            '@tipo_taxa'            => '0',                                         # opcional # ( 0 = R$ | 1 = % )
//            '@tipo_parcelamento'    => '',                                        # opcional # ( A: Administradora | L: Lojista )
//            '@num_parcelas'         => '',                                        # opcional
            'demonstrativo' => $this->cobrancaDemonstrativo,                        # array()
            /*'desconto'          => array(
                '@valor'            => '2.00',
                '@tipo_desconto'    => '0',
                '@antecedencia'     => '5'
            ),*/
            'multa' => array(
                '@valor'            => $this->converterValor($this->cobrancaMultaValor),    # opcional
                '@tipo_multa'       => '1',                                                 # ( 0 = R$ | 1 = % )
                '@valor_dia'        => $this->converterValor($this->cobrancaMultaMoraDia),  # opcional ( valor em reais ou porcentagem )
                '@tipo_multa_dia'   => '1',                                                 # ( 0 = R$ | 1 = % )
                '@atraso'           => '20'                                                 # ( dias em atraso após o vencimento que será aceito o pagamento ) Max: 20
            )
        );

        // Agendamento
        $xml[$elementoBase]['agendamento'] = array(
            '@vencimento'       => $this->cobrancaVencimento,
            '@ultimo_dia'       => 'n',                     # opcional ( s | n )
            //            '@antecedencia'     => '10',      # opcional ( número de dias  )
            '@periodicidade'    => '1',                     # opcional ( número de meses de intervalo entre o envio de cada cobrança agendada | 1: "default" )
            '@periodos'         => '0',                     # opcional ( número de períodos, contando o primeiro, em que a cobrança agendada deverá ser enviada. Se o valor for 0, a cobrança será agendada por período indeterminado "o default é 1 período" )
            '@sem_vencimento'   => 'n',                     # opcional ( s | n ) - indica se a cobrança terá limite de vencimento "default - N"
            '@'                 => 'Via WebService'
        );

        // Sacado
        $xml[$elementoBase]['sacado'] = array(
            '@grupo'            => 'WebService',                                # opcional
            '@codigo'           => $this->cobrancaSacadoCodigo,                 # opcional
            '@envio'            => 'n',                                         # opcional ( e: apenas email | p: apenas impressa pelo correio | b: das duas formas anteriores | n: só é feito o registro da cobrança "default" )
            'nome'              => $this->cobrancaSacadoNome,
            'email'             => ($this->devMode ? array('teste@f2b.com.br') : $this->cobrancaSacadoEmail),   # array()
            'endereco' => array(                                                # opcional
                '@logradouro'   => $this->cobrancaSacadoEnderecoLogradouro,
                '@numero'       => $this->cobrancaSacadoEnderecoNumero,
                '@complemento'  => $this->cobrancaSacadoEnderecoComplemento,    # opcional
                '@bairro'       => $this->cobrancaSacadoEnderecoBairro,
                '@cidade'       => $this->cobrancaSacadoEnderecoCidade,
                '@estado'       => $this->cobrancaSacadoEnderecoEstado,
                '@cep'          => $this->cobrancaSacadoEnderecoCep
            ),
//            'telefone' => array(
//                '@ddd'      => $this->cobrancaSacadoTelefoneDDD,
//                '@numero'   => $this->cobrancaSacadoTelefoneNumero
//            ),
//            'telefone_com' => array(
//                '@ddd_com'      => '',
//                '@numero_com'   => ''
//            ),
//            'telefone_cel' => array(
//                '@ddd_cel'      => $this->cobrancaSacadoCelDDD,
//                '@numero_cel'   => $this->cobrancaSacadoCelNumero
//            ),
//            'cpf'           => $this->cobrancaSacadoCpfCnpj,                   # opcional
//            'cnpj'          => $this->cobrancaSacadoCpfCnpj,                   # opcional
            'observacao'    => $this->cobrancaSacadoObs                        # opcional
        );

        // Verifica se foi passado um número de telefone para o sacado para ADD no XML
        if (!is_null($this->cobrancaSacadoTelefoneDDD) && !is_null($this->cobrancaSacadoTelefoneNumero)) {
            $xml[$elementoBase]['sacado']['telefone'] = array(
                '@ddd'      => $this->cobrancaSacadoTelefoneDDD,
                '@numero'   => $this->cobrancaSacadoTelefoneNumero
            );
        }

        // Verifica se foi passado um número de celular para o sacado para ADD no XML
        if (!is_null($this->cobrancaSacadoCelDDD) && !is_null($this->cobrancaSacadoCelNumero)) {
            $xml[$elementoBase]['sacado']['telefone_cel'] = array(
                '@ddd_cel'      => $this->cobrancaSacadoCelDDD,
                '@numero_cel'   => $this->cobrancaSacadoCelNumero
            );
        }

        // Verifica o tipo de sacado para ADD no XML
        $tipo = $this->cobrancaSacadoTipo == 'J' ? 'cnpj' : 'cpf';
        $xml[$elementoBase]['sacado'][$tipo] = $this->cobrancaSacadoCpfCnpj;

        return $xml;
    }

    private function f2bSegundaVia($elementoBase) {
        $this->ws['service'] = 'WSBillingSegundaVia';

        $xml[$elementoBase] = array(
            'xmlns:m' => 'http://www.f2b.com.br/soap/wsbillingsegundavia.xsd'
        );

        // Mensagem
        $xml[$elementoBase]['mensagem'] = array(
            '@data'     => date('Y-m-d'),
            '@numero'   => $this->xmlUID
        );

        // Cliente (dados da conta)
        $xml[$elementoBase]['cliente'] = array(
            '@conta'    => $this->accountId,
            '@senha'    => $this->password
        );

        // Dados do sacado
        $xml[$elementoBase]['sacado'] = array(
            '@txt_email'                    => '',          # email do sacado
            '@num_cpf'                      => '',          # cpf/cnpj do sacado
            '@somente_registradas'          => '',          # ( 1 ou vazio: Cobranças Registradas | 2: Cobranças registradas e pagas )
            '@vencimento'                   => '',          # opcional
            '@vencimento_final'             => ''           # opcional
        );
        return $xml;
    }

    private function f2bAcaoCobranca($elementoBase) {
        $this->ws['service'] = 'WSBillingAction';

        $xml[$elementoBase] = array(
            'xmlns:m' => 'http://www.f2b.com.br/soap/wsbillingaction.xsd'
        );

        // Mensagem
        $xml[$elementoBase]['mensagem'] = array(
            '@data'     => date('Y-m-d'),
            '@numero'   => $this->xmlUID
        );

        // Cliente (dados da conta)
        $xml[$elementoBase]['cliente'] = array(
            '@conta'    => $this->accountId,
            '@senha'    => $this->password
        );

        // Acao Cobranca
        $xml[$elementoBase]['acao_cobranca'] = array(
            '@numero'                       => $this->acaoCobrancaNumero,
            '@cancelar_cobranca'            => $this->acaoCobrancaCancelarCobranca,
            '@registrar_pagamento'          => $this->acaoCobrancaRegistrarPagamento,
            '@registrar_pagamento_valor'    => $this->acaoCobrancaRegistrarPagamentoValor,       # opcional
            '@dt_registrar_pagamento'       => $this->acaoCobrancaDtRegistrarPagamento,          # opcional
            '@cancelar_multa'               => $this->acaoCobrancaCancelarMulta,
            '@permitir_pagamento'           => $this->acaoCobrancaPermitirPagamento,
            '@dt_permitir_pagamento'        => $this->acaoCobrancaDtPermitirPagamento,           # opcional
            '@reenviar_email'               => $this->acaoCobrancaReenviarEmail,
            '@email_tosend'                 => $this->acaoCobrancaEmailToSend,                   # opcional
            'acao_agendamento'              => array(
                '@numero'               => $this->acaoCobrancaAgendamentoNumero,
                '@cancelar_agendamento' => $this->acaoCobrancaAgendamentoCancelarAgendamento
            )
        );
        return $xml;

    }

    private function f2bSituacaoCobranca($elementoBase) {
        $this->ws['service'] = 'WSBillingStatus';

        $xml[$elementoBase] = array(
            'xmlns:m' => 'http://www.f2b.com.br/soap/wsbillingstatus.xsd'
        );

        // Mensagem
        $xml[$elementoBase]['mensagem'] = array(
            '@data'     => date('Y-m-d'),
            '@numero'   => $this->xmlUID
        );

        // Cliente (dados da conta)
        $xml[$elementoBase]['cliente'] = array(
            '@conta'    => $this->accountId,
            '@senha'    => $this->password
        );

        // Cobrança por numeracao
        /*$xml[$elementoBase]['cobranca'] = array(
            '@numero'               => '',
            '@numero_final'         => ''
        );*/

        // Cobrança por data de registro
        /*$xml[$elementoBase]['cobranca'] = array(
            '@registro'             => '',
            '@registro_final'       => ''
        );*/

        // Cobrança por data de vencimento
        /*$xml[$elementoBase]['cobranca'] = array(
            '@vencimento'           => '',
            '@vencimento_final'     => ''
        );*/

        // Cobrança por data de processamento
        /*$xml[$elementoBase]['cobranca'] = array(
            '@processamento'        => '',
            '@processamento_final'  => ''
        );*/

        // Cobrança por data de baixa/credito
        $xml[$elementoBase]['cobranca'] = array(
            '@credito'              => $this->cobrancaCreditoInicial,
            '@credito_final'        => $this->cobrancaCreditoFinal
        );

        // Cobrança por codigo de sacado
        /*$xml[$elementoBase]['cobranca'] = array(
            '@cod_sacado'           => ''
        );*/

        // Cobrança por codigo do grupo de cobranca
        /*$xml[$elementoBase]['cobranca'] = array(
            '@cod_grupo'            => ''
        );*/

        // Cobrança por tipo de pagamento
        /*$xml[$elementoBase]['cobranca'] = array(
            '@tipo_pagamento'       => ''
        );*/

        // Cobrança por numero do documento
        /*$xml[$elementoBase]['cobranca'] = array(
            '@numero_documento'     => ''
        );*/
        return $xml;
    }
}
